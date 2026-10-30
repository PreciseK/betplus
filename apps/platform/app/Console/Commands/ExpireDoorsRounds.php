<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Games\Doors\DoorsService;
use App\Models\DoorsRound;
use App\Models\Player;
use Illuminate\Console\Command;
use Throwable;

class ExpireDoorsRounds extends Command
{
    protected $signature = 'doors:expire-rounds';
    protected $description = 'Settle expired, abandoned Doors rounds and release their exposure reservations.';
    public function handle(DoorsService $doors): int
    {
        $failed = 0;
        DoorsRound::with('ticket')->where('status', 'IN_PLAY')->where('expiresAt', '<=', now())->chunkById(100, function ($rounds) use ($doors, &$failed): void {
            foreach ($rounds as $round) {
                // One bad round must not strand every later round's exposure reservation.
                try { $doors->read(Player::findOrFail($round->playerId), $round->ticket->reference); }
                catch (Throwable $e) { report($e); $failed++; $this->error("Doors round {$round->id} failed to settle: {$e->getMessage()}"); }
            }
        });
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
