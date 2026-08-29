<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Domain\Payout\DispatchPayout;
use App\Domain\Payout\PayoutStatusMapper;
use App\Domain\Payout\SettlePayoutStatus;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\Player;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Mirrors PollCollectionStatusJob's shape (Story 2.4) for the payout side (Story 4.3).
 * Also the mechanism that re-attempts a FLOAT_HALTED or QUEUED/MANUAL_REVIEW payout
 * once conditions change, since this job re-checks float/manual-review state on
 * every run rather than assuming a payout was dispatched.
 */
class PollPayoutStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const INITIAL_DELAY_SECONDS = 30;
    private const MAX_BACKOFF_SECONDS = 1800;
    private const GIVE_UP_AFTER_SECONDS = 86400;

    public function __construct(
        public readonly int $payoutId,
        public readonly int $attempt = 0,
    ) {
    }

    public function handle(OpayGateway $opay, DispatchPayout $dispatch, SettlePayoutStatus $settle, PayoutStatusMapper $mapper): void
    {
        $payout = Payout::find($this->payoutId);
        if ($payout === null || $payout->confirmedAt !== null) {
            return;
        }

        $elapsedSeconds = now()->timestamp - $payout->createdAt->timestamp;
        if ($elapsedSeconds >= self::GIVE_UP_AFTER_SECONDS) {
            $this->escalate($payout);

            return;
        }

        if ($payout->opayOrderNo === null) {
            // Never actually dispatched (QUEUED/FLOAT_HALTED/MANUAL_REVIEW) — try again
            // rather than polling a status query with nothing to look up.
            $player = Player::find($payout->playerId);
            if ($player !== null) {
                $dispatch->send($payout, $player);
            }
            $this->reschedule();

            return;
        }

        $result = $opay->queryPayoutStatus($dispatch->numericOrderNo($payout));
        if ($result['status'] === 'error') {
            $this->reschedule();

            return;
        }

        $settle->apply($payout, $result['providerStatus']);

        if ($mapper->isStillInFlight($result['providerStatus'])) {
            $this->reschedule();
        }
    }

    private function reschedule(): void
    {
        $delay = min(self::INITIAL_DELAY_SECONDS * (2 ** $this->attempt), self::MAX_BACKOFF_SECONDS);
        self::dispatch($this->payoutId, $this->attempt + 1)->delay(now()->addSeconds($delay));
    }

    private function escalate(Payout $payout): void
    {
        $payout->update(['manualReviewRequired' => true]);

        AuditLog::create([
            'actorType' => 'system',
            'action' => 'payout.unknown.escalated',
            'targetTable' => 'payout',
            'targetId' => $payout->id,
            'reason' => 'No confirming status within 24 hours (mirrors REQ-PAY-015 for payouts).',
        ]);
    }
}
