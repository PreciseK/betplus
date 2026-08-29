<?php

declare(strict_types=1);

namespace App\Domain\Games\Draw;

/**
 * No 5/90 draw operator is contracted yet (PRD's C-xx items are unconfirmed) —
 * mirrors StubRegistryClient's situation exactly. Always succeeds with a
 * deterministic-looking reference so the rest of the pipeline (async dispatch,
 * receipt SMS, reconciliation, circuit breaker) is genuinely exercised in dev and
 * staging without a real integration to point at.
 */
final class StubDrawPartnerAdapter implements DrawPartnerAdapter
{
    public function submitEntry(string $drawId, array $selectedNumbers): SubmitEntryResult
    {
        return new SubmitEntryResult(success: true, partnerReference: 'STUB-' . $drawId . '-' . bin2hex(random_bytes(6)));
    }

    public function getReceipt(string $partnerReference): array
    {
        return ['status' => 'lodged', 'lodgedAt' => now()->toIso8601String()];
    }

    public function getDrawCalendar(): array
    {
        return [];
    }

    public function getResults(string $partnerReference): ?array
    {
        return null; // no partner exists to have resulted anything yet
    }

    public function reconcile(array $partnerReferences): array
    {
        // A real partner might drop or delay confirmation of some entries — a stub
        // that always confirms everything would make the P1-exception path (REQ-HG-
        // 039) untestable. Reporting nothing as confirmed is the honest default: it
        // surfaces every submitted entry as an exception until a real partner exists,
        // which is the correct state to be in with no partner behind this adapter.
        return [];
    }
}
