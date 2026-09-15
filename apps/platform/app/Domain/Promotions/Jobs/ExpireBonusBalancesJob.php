<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Jobs;

use App\Domain\Wallet\WalletService;
use App\Models\Player;
use App\Models\PlayerPromotionalMilestone;
use App\Models\PlayerWallet;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ExpireBonusBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ACCRA_TZ = 'Africa/Accra';

    public function handle(WalletService $wallet): void
    {
        $now = Carbon::now(self::ACCRA_TZ);

        $expiredMilestones = PlayerPromotionalMilestone::where('isAwarded', true)
            ->whereNotNull('bonusExpiresAt')
            ->where('bonusExpiresAt', '<=', $now)
            ->get();

        foreach ($expiredMilestones as $milestone) {
            $player = Player::find($milestone->playerId);
            if ($player === null) {
                continue;
            }

            $playerWallet = PlayerWallet::where('playerId', $player->id)->first();
            if ($playerWallet === null || $playerWallet->bonusBalanceKobo <= 0) {
                continue;
            }

            // Burn remaining bonus balance
            $amountToExpireKobo = (int) $playerWallet->bonusBalanceKobo;
            try {
                $wallet->expireBonusPlayBalance(
                    $player,
                    $amountToExpireKobo,
                    'milestone_expiry',
                    $milestone->id
                );

                Log::info("Expired {$amountToExpireKobo} kobo bonus balance for player {$player->id}.");
            } catch (\Throwable $e) {
                Log::error("Failed to expire bonus balance for player {$player->id}: " . $e->getMessage());
            }
        }
    }
}
