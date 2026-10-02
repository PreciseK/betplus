<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile Bot Verification Service.
 *
 * Verifies Turnstile interactive/invisible challenge tokens against Cloudflare's
 * siteverify API. Protects authentication, registration, and OTP completion
 * against automated bots, credential stuffing, and SMS pumping/toll fraud.
 */
class TurnstileVerifier
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly ?string $secretKey = null,
        private readonly bool $enabled = false,
    ) {
    }

    public static function make(): self
    {
        return new self(
            secretKey: (string) config('services.cloudflare.turnstile_secret_key', ''),
            enabled: (bool) config('services.cloudflare.turnstile_enabled', false),
        );
    }

    /**
     * Verify a Turnstile response token.
     *
     * @param string|null $token The cf-turnstile-response token submitted by client
     * @param string|null $ipAddress Optional client IP address
     * @return bool True if verification succeeded or Turnstile is disabled
     */
    public function verify(?string $token, ?string $ipAddress = null): bool
    {
        // If Turnstile is globally disabled or no secret key is provisioned, allow request
        if (!$this->enabled || empty($this->secretKey)) {
            return true;
        }

        // Token must be provided when Turnstile is enabled
        if ($token === null || trim($token) === '') {
            return false;
        }

        try {
            $payload = [
                'secret' => $this->secretKey,
                'response' => trim($token),
            ];

            if ($ipAddress !== null && $ipAddress !== '') {
                $payload['remoteip'] = $ipAddress;
            }

            $response = Http::asForm()
                ->timeout(5)
                ->connectTimeout(3)
                ->post(self::SITEVERIFY_URL, $payload);

            if (!$response->successful()) {
                Log::warning('Turnstile verification HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            $body = $response->json();
            $success = is_array($body) && ($body['success'] ?? false) === true;

            if (!$success) {
                Log::info('Turnstile verification rejected', [
                    'error_codes' => $body['error-codes'] ?? [],
                    'ip' => $ipAddress,
                ]);
            }

            return $success;
        } catch (Throwable $e) {
            Log::error('Turnstile verification exception', [
                'error' => $e->getMessage(),
            ]);

            // Fail-closed for security if configured, or fail-open if connection fails
            return false;
        }
    }
}
