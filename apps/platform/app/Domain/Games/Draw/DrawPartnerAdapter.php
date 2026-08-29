<?php

declare(strict_types=1);

namespace App\Domain\Games\Draw;

/**
 * REQ-HG-031 — the internal interface the Draw Partner Bridge is written against, so
 * switching or adding a 5/90 draw operator never touches the Heritage engine or
 * CreateHeritageTicket. Mirrors the RegistryClient/StubRegistryClient shape from
 * Epic 5 — same reason: no real partner is contracted yet (PRD's C-xx items), so the
 * bound implementation is a stub, not a fabricated integration.
 */
interface DrawPartnerAdapter
{
    /** @param list<int> $selectedNumbers exactly 5 numbers, 1-90 */
    public function submitEntry(string $drawId, array $selectedNumbers): SubmitEntryResult;

    /** @return array{status: string, lodgedAt: ?string}|null null if the partner has no record of this reference */
    public function getReceipt(string $partnerReference): ?array;

    /** @return list<array{drawId: string, drawName: string, scheduledAt: string, cutoffAt: string}> */
    public function getDrawCalendar(): array;

    /** @return array{won: bool, prizeDescription: ?string}|null null if not yet resulted */
    public function getResults(string $partnerReference): ?array;

    /**
     * REQ-HG-039 — daily reconciliation. Returns the subset of $partnerReferences the
     * partner confirms; anything submitted but absent from this list is a P1
     * exception (a record without a counterpart).
     *
     * @param list<string> $partnerReferences
     * @return list<string>
     */
    public function reconcile(array $partnerReferences): array;
}
