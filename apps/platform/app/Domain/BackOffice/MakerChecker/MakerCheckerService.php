<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\MakerChecker;

use App\Models\InstitutionUser;
use App\Models\ReviewableChange;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Story 6.3 — the one shared workflow: DRAFT -> AWAITING_APPROVAL -> (APPROVED|
 * REJECTED) -> APPLIED (REQ-BO-015). REQ-BO-017: a maker may never approve their own
 * change — enforced here, not left to callers to remember.
 */
final class MakerCheckerService
{
    /** @var array<string, class-string<ReviewableChangeApplier>> */
    private const APPLIERS = [
        'prize_table_publish' => PrizeTablePublishApplier::class,
        'manual_credit_debit' => ManualCreditDebitApplier::class,
    ];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $beforeSnapshot
     */
    public function propose(string $changeType, array $payload, ?array $beforeSnapshot, InstitutionUser $maker, string $justification): ReviewableChange
    {
        if (!array_key_exists($changeType, self::APPLIERS)) {
            throw new RuntimeException("Unregistered reviewable change type: $changeType");
        }

        return ReviewableChange::create([
            'changeType' => $changeType,
            'status' => 'AWAITING_APPROVAL',
            'payload' => $payload,
            'beforeSnapshot' => $beforeSnapshot,
            'makerId' => $maker->id,
            'makerJustification' => $justification,
            'submittedAt' => now(),
        ]);
    }

    public function approve(ReviewableChange $change, InstitutionUser $checker): ReviewableChange
    {
        if ($change->status !== 'AWAITING_APPROVAL') {
            throw new RuntimeException('Only a change AWAITING_APPROVAL can be approved.');
        }
        if ($change->makerId === $checker->id) {
            // REQ-BO-017 / REQ-SEC-023.
            throw new RuntimeException('A maker may not approve their own change.');
        }

        return DB::transaction(function () use ($change, $checker) {
            $change->update(['status' => 'APPROVED', 'checkerId' => $checker->id, 'checkerDecisionAt' => now()]);

            $applierClass = self::APPLIERS[$change->changeType];
            app($applierClass)->apply($change);

            $change->update(['status' => 'APPLIED', 'appliedAt' => now()]);

            return $change->refresh();
        });
    }

    public function reject(ReviewableChange $change, InstitutionUser $checker, string $reason): ReviewableChange
    {
        if ($change->status !== 'AWAITING_APPROVAL') {
            throw new RuntimeException('Only a change AWAITING_APPROVAL can be rejected.');
        }
        if ($change->makerId === $checker->id) {
            throw new RuntimeException('A maker may not decide their own change.');
        }

        // REQ-BO-018 — rejection requires a reason and preserves the draft (the
        // payload/beforeSnapshot are untouched; only status and the decision are recorded).
        $change->update([
            'status' => 'REJECTED',
            'checkerId' => $checker->id,
            'checkerDecisionAt' => now(),
            'rejectionReason' => $reason,
        ]);

        return $change->refresh();
    }
}
