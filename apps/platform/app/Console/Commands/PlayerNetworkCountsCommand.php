<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\MobileNetwork;
use App\Models\Player;
use Illuminate\Console\Command;

class PlayerNetworkCountsCommand extends Command
{
    protected $signature = 'players:network-counts';

    protected $description = 'Count non-deleted player accounts by their selected mobile network';

    public function handle(): int
    {
        $counts = Player::whereNull('deletedAt')
            ->selectRaw('mobileNetwork, COUNT(*) AS playerCount')
            ->groupBy('mobileNetwork')->pluck('playerCount', 'mobileNetwork');

        $rows = [];
        foreach (MobileNetwork::cases() as $network) {
            $rows[] = [$network->label(), (int) ($counts[$network->value] ?? 0)];
        }
        $rows[] = ['Not recorded', (int) ($counts[''] ?? 0)];
        $rows[] = ['Total', (int) $counts->sum()];
        $this->table(['Mobile network', 'Player accounts'], $rows);
        $this->comment('Player-selected networks. Includes active, frozen and closed accounts; excludes deleted accounts and incomplete signups.');

        return self::SUCCESS;
    }
}
