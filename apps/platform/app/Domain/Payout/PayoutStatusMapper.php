<?php

declare(strict_types=1);

namespace App\Domain\Payout;

/**
 * Story 4.3 (REQ-PO-006) — the enumeration is handled explicitly; anything outside it
 * escalates for manual review rather than being read as a failure. A codebase that
 * defaults an unrecognised status to "failed" tells a winning player they lost when
 * OPay may simply have added a new code.
 */
final class PayoutStatusMapper
{
    private const KNOWN_STATUSES = ['INITIAL', 'PENDING', 'CHECKING', 'SUCCESS', 'FAIL', 'CLOSE', 'RETURN'];
    private const TERMINAL_SUCCESS = ['SUCCESS'];
    private const TERMINAL_FAILURE = ['FAIL', 'CLOSE', 'RETURN'];

    public function isKnown(string $providerStatus): bool
    {
        return in_array($providerStatus, self::KNOWN_STATUSES, true);
    }

    public function isTerminalSuccess(string $providerStatus): bool
    {
        return in_array($providerStatus, self::TERMINAL_SUCCESS, true);
    }

    /** True only for a KNOWN terminal-failure code — an unrecognised code is never treated as failure. */
    public function isTerminalFailure(string $providerStatus): bool
    {
        return in_array($providerStatus, self::TERMINAL_FAILURE, true);
    }

    public function isStillInFlight(string $providerStatus): bool
    {
        return in_array($providerStatus, ['INITIAL', 'PENDING', 'CHECKING'], true);
    }

    /** @return "requested"|"processing"|"paid"|"needs-attention"|"manual-review" */
    public function toDisplayStatus(string $providerStatus): string
    {
        return match (true) {
            $providerStatus === 'INITIAL' => 'requested',
            in_array($providerStatus, ['PENDING', 'CHECKING'], true) => 'processing',
            $providerStatus === 'SUCCESS' => 'paid',
            in_array($providerStatus, ['FAIL', 'CLOSE', 'RETURN'], true) => 'needs-attention',
            default => 'manual-review', // unrecognised code — REQ-PO-006
        };
    }

    /**
     * REQ-PO-012 — payout requests carry amount in kobo; REQ-PO-011 the payout callback
     * reports amount in Naira ("2000.00" form). Parsing one as the other produces a
     * 100x reconciliation error on every payout — this is the one conversion point,
     * tested directly (BlackRedTicketTest sibling: PayoutUnitConversionTest).
     */
    public function nairaStringToKobo(string $naira): int
    {
        return (int) round(((float) $naira) * 100);
    }
}
