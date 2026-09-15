<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Games\Economics\BlackRedPoolSettlementService;
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\PariMutuelPoolParams;
use App\Models\PoolDraw;
use Illuminate\Console\Command;

class DrawBlackRedPoolCommand extends Command
{
    protected $signature = 'economics:draw-blackred-pool';
    protected $description = 'Settles every BlackRed pari-mutuel pool past its closesAt and opens the next one';

    public function handle(BlackRedPoolSettlementService $settlement, EconomicsConfigResolver $configs): int
    {
        $config = $configs->resolveFor('BLACKRED');
        if ($config === null || $config->activeModel !== 'PARI_MUTUEL_POOL') {
            return self::SUCCESS;
        }
        $params = PariMutuelPoolParams::fromArray($config->paramsJson);

        $duePools = PoolDraw::where('gameCode', 'BLACKRED')->where('status', 'open')->where('closesAt', '<=', now())->get();

        foreach ($duePools as $pool) {
            $settlement->settle($pool, $params);
        }

        $this->info("Settled {$duePools->count()} BlackRed pool(s).");

        return self::SUCCESS;
    }
}
