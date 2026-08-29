<?php

declare(strict_types=1);

namespace BlackRed\Auth;

use BlackRed\Database\Connection;
use BlackRed\Sms\SmsService;
use BlackRed\Logging\Logger;

/**
 * OtpService — generates 4-digit OTPs, stores them in `authtoken`, and
 * sends the code via SMS. Same code can be retrieved via *920*995# (the
 * USSD endpoint reads codePlain directly from the row we just wrote).
 *
 * Flow on issue():
 *   1. DELETE all rows in authtoken for the phone (fresh slate). This is
 *      explicit per product decision — only one active OTP per phone at a
 *      time, and re-issuing invalidates anything older.
 *   2. INSERT a single new row with codehash (SHA-512, kept for backwards
 *      compatibility with legacy callers) + codePlain (the unhashed code
 *      that USSD selects to display) + expiresAt = NOW() + 5 min.
 *   3. Send the SMS via SmsService. SMS failure is logged but doesn't
 *      raise — the user still has the USSD fallback.
 *
 * Verification is handled by AuthtokenVerifier (separate class — owned the
 * lookup side of the table, this class owns the issue side).
 *
 * Code format: 4 numeric digits. The product decision was to keep the
 * 4-char width of the legacy USSD-issued code, but drop alphabetic chars
 * since users find numeric easier to type on phone keypads.
 *
 * Storage format: the authtoken table stores phonenumber in local 0XXXX
 * form (10 digits) for compatibility with the existing live USSD code.
 * Callers pass canonical 233XXXX form (12 digits) and we convert.
 */
final class OtpService
{
    /** OTP lifetime in seconds. 5 minutes per product spec. */
    public const TTL_SECONDS = 300;

    /** Length of the numeric OTP. */
    public const CODE_LENGTH = 4;

    public function __construct(
        private readonly Connection $db,
        private readonly SmsService $sms,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Issue a fresh OTP for the given phone, store it, and send via SMS.
     *
     * @param string $phoneCanonical 233XXXXXXXXX form (12 digits)
     * @param string $purpose        'signup' | 'login' | 'pin_reset'
     * @param ?int   $playerId       Player.id when known (forgot-PIN); null on signup
     *
     * @return array{
     *   code: string,        plaintext, returned ONLY for tests/logging — never to clients
     *   smsSent: bool,       true if Hubtel accepted the send
     *   expiresAt: string,   ISO-ish datetime ('YYYY-MM-DD HH:MM:SS')
     * }
     */
    public function issue(string $phoneCanonical, string $purpose, ?int $playerId = null): array
    {
        $phoneLocal = $this->canonicalToLocal($phoneCanonical);
        if ($phoneLocal === null) {
            throw new \InvalidArgumentException('phoneCanonical must be 12-digit 233-prefixed form');
        }
        if (!in_array($purpose, ['signup', 'login', 'pin_reset'], true)) {
            throw new \InvalidArgumentException("invalid purpose: $purpose");
        }

        $code = $this->generateNumericCode(self::CODE_LENGTH);
        $hash = hash('sha512', $code);
        $expiresAtTs = time() + self::TTL_SECONDS;
        $expiresAt = date('Y-m-d H:i:s', $expiresAtTs);

        // Atomic clean-slate write: delete + insert in one txn so USSD never
        // sees a window where 2+ active codes exist for the same phone.
        $this->db->transactional(function (Connection $db) use (
            $phoneLocal, $hash, $code, $expiresAt, $purpose
        ) {
            $db->execute(
                'DELETE FROM authtoken WHERE phonenumber = :phone',
                ['phone' => $phoneLocal]
            );
            $db->execute(
                'INSERT INTO authtoken
                    (phonenumber, codehash, codePlain, expirydate, expirytime,
                     expiresAt, purpose, consumedAt, request_time)
                 VALUES
                    (:phone, :hash, :plain, DATE(:expires1), TIME(:expires2),
                     :expires3, :purpose, NULL, NOW())',
                [
                    'phone'    => $phoneLocal,
                    'hash'     => $hash,
                    'plain'    => $code,
                    'expires1' => $expiresAt,
                    'expires2' => $expiresAt,
                    'expires3' => $expiresAt,
                    'purpose'  => $purpose,
                ]
            );
        });

        // Send via SMS. Hubtel failure isn't fatal — user has USSD fallback.
        $body = $this->composeSmsBody($code, $purpose);
        $smsSent = $this->sms->sendOtp(
            msisdn:       $phoneCanonical,
            messageBody:  $body,
            playerId:     $playerId,
            relatedTable: 'authtoken',
            relatedId:    null, // authtoken.id is small-int legacy; not worth joining
        );

        $this->logger->info('otp_issued', [
            'phone'    => $phoneCanonical,
            'purpose'  => $purpose,
            'smsSent'  => $smsSent,
            'expiresAt'=> $expiresAt,
        ]);

        return [
            'code'      => $code,
            'smsSent'   => $smsSent,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Compose the SMS body for an OTP. Kept short — Hubtel charges per
     * 160-char segment, and shorter copy reads faster on feature phones.
     */
    private function composeSmsBody(string $code, string $purpose): string
    {
        $intent = match ($purpose) {
            'signup'    => 'sign up',
            'pin_reset' => 'reset your PIN',
            default     => 'verify your phone',
        };
        return "BlackRed: your code is {$code}. Use it to {$intent}. Expires in 5 min. Do not share.";
    }

    /**
     * Cryptographically-strong N-digit numeric code. Uses random_int so the
     * output is suitable for security tokens (not just pseudo-random).
     */
    private function generateNumericCode(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string)random_int(0, 9);
        }
        return $out;
    }

    /**
     * Convert canonical 233XXXXXXXXX → local 0XXXXXXXXX (10 digits).
     * Matches the storage format the legacy USSD endpoint expects.
     */
    private function canonicalToLocal(string $canonical): ?string
    {
        if (strlen($canonical) !== 12 || !str_starts_with($canonical, '233')) {
            return null;
        }
        return '0' . substr($canonical, 3);
    }
}