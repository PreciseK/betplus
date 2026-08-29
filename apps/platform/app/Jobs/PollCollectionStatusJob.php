<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Payments\Providers\Opay\OpayGateway;
use App\Domain\Wallet\FundingService;
use App\Models\AuditLog;
use App\Models\Collection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * REQ-PAY-015 — no callback within 90s, poll status with exponential backoff for up to
 * 24h before marking UNKNOWN and escalating. A payment is never assumed failed on
 * timeout alone: the terminal states here are 'paid', 'failed' (OPay said so), or
 * 'unknown' (OPay never said so within the window) — never a silent 'failed'.
 */
class PollCollectionStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const INITIAL_DELAY_SECONDS = 90;
    private const MAX_BACKOFF_SECONDS = 1800; // 30 minutes between polls, at most
    private const GIVE_UP_AFTER_SECONDS = 86400; // 24 hours from first creation

    public function __construct(
        public readonly int $collectionId,
        public readonly int $attempt = 0,
    ) {
    }

    public function handle(OpayGateway $opay, FundingService $funding): void
    {
        $collection = Collection::find($this->collectionId);
        if ($collection === null || in_array($collection->status, ['paid', 'failed', 'unknown'], true)) {
            return; // already resolved by an OTP response or callback — nothing to do
        }

        // Plain integer subtraction, not diffInSeconds() — Carbon's diff sign/direction
        // conventions have shifted across versions and are easy to get backwards; a
        // timestamp subtraction can't be ambiguous.
        $elapsedSeconds = now()->timestamp - $collection->createdAt->timestamp;

        if ($elapsedSeconds >= self::GIVE_UP_AFTER_SECONDS) {
            $this->markUnknownAndEscalate($collection);
            return;
        }

        $result = $opay->queryCollectionStatus($collection->reference);

        if ($result['status'] === 'paid' || $result['status'] === 'failed') {
            $funding->resolveByReference($collection->reference, $result['status']);
            return;
        }

        // Still unresolved — reschedule with exponential backoff, capped.
        $delay = min(self::INITIAL_DELAY_SECONDS * (2 ** $this->attempt), self::MAX_BACKOFF_SECONDS);
        self::dispatch($this->collectionId, $this->attempt + 1)->delay(now()->addSeconds($delay));
    }

    private function markUnknownAndEscalate(Collection $collection): void
    {
        $collection->forceFill(['status' => 'unknown'])->save();

        // No paging/alerting pipeline exists yet — this is the escalation record a real
        // one would consume. Finance/ops needs to see this in an exception queue
        // (Epic 6, REQ-BO-006), not just have it logged where nobody looks.
        AuditLog::create([
            'actorType' => 'system',
            'action' => 'collection.unknown.escalated',
            'targetTable' => 'collection',
            'targetId' => $collection->id,
            'reason' => 'No callback or resolving status poll within 24 hours (REQ-PAY-015).',
        ]);
    }
}
