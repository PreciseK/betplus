<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Games\Economics\CagedPoolSettlementService;
use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\PariMutuelPoolParams;
use App\Models\PoolDraw;
use Illuminate\Console\Command;

class DrawCagedPoolCommand extends Command
{
    protected $signature = 'economics:draw-caged-pool';
    protected $description = 'Settles every Caged pari-mutuel pool past its closesAt and opens the next one';

    public function handle(CagedPoolSettlementService $settlement, EconomicsConfigResolver $configs): int
    {
        $config = $configs->resolveFor('CAGED');
        if ($config === null || $config->activeModel !== 'PARI_MUTUEL_POOL') {
            return self::SUCCESS;
        }
        $params = PariMutuelPoolParams::fromArray($config->paramsJson);

        $duePools = PoolDraw::where('gameCode', 'CAGED')->where('status', 'open')->where('closesAt', '<=', now())->get();

        foreach ($duePools as $pool) {
            $settlement->settle($pool, $params);
        }

        $this->info("Settled {$duePools->count()} Caged pool(s).");

        return self::SUCCESS;
    }
}
