<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\MakerChecker;

use App\Domain\Wallet\WalletService;
use App\Models\AuditLog;
use App\Models\Player;
use App\Models\ReviewableChange;
use InvalidArgumentException;

/**
 * Story 6.6 (REQ-BO-003, REQ-BO-006). Payload: {player_id, direction: 'credit'|
 * 'debit', balance: 'PLAY'|'WINNINGS', amount_kobo}. The justification lives on
 * ReviewableChange.makerJustification (the maker-checker record); this additionally
 * writes its own AuditLog row, since "every operator action" (REQ-BO-002) is a
 * broader ledger than just maker-checker's own workflow state.
 */
final class ManualCreditDebitApplier implements ReviewableChangeApplier
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    public function apply(ReviewableChange $change): void
    {
        $payload = $change->payload;
        $player = Player::findOrFail($payload['player_id']);
        $balance = $payload['balance'];
        $amountKobo = (int) $payload['amount_kobo'];

        $group = match ($payload['direction']) {
            'credit' => $this->wallet->manualCredit($player, $balance, $amountKobo, 'reviewableChange', $change->id),
            'debit' => $this->wallet->manualDebit($player, $balance, $amountKobo, 'reviewableChange', $change->id),
            default => throw new InvalidArgumentException("Invalid direction: {$payload['direction']}"),
        };

        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $change->checkerId,
            'action' => 'wallet.manual_' . $payload['direction'],
            'targetTable' => 'player',
            'targetId' => $player->id,
            'after' => ['ledgerTransactionGroup' => $group, 'amountKobo' => $amountKobo, 'balance' => $balance],
            'reason' => $change->makerJustification,
        ]);
    }
}
