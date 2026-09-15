<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Games\Economics\EconomicsConfigResolver;
use App\Domain\Games\Economics\HeritagePoolSettlementService;
use App\Domain\Games\Economics\PariMutuelPoolParams;
use App\Models\PoolDraw;
use Illuminate\Console\Command;

class DrawHeritagePoolCommand extends Command
{
    protected $signature = 'economics:draw-heritage-pool';
    protected $description = 'Settles every Heritage pari-mutuel pool past its closesAt and opens the next one';

    public function handle(HeritagePoolSettlementService $settlement, EconomicsConfigResolver $configs): int
    {
        $config = $configs->resolveFor('HERITAGE');
        if ($config === null || $config->activeModel !== 'PARI_MUTUEL_POOL') {
            return self::SUCCESS;
        }
        $params = PariMutuelPoolParams::fromArray($config->paramsJson);

        $duePools = PoolDraw::where('gameCode', 'HERITAGE')->where('status', 'open')->where('closesAt', '<=', now())->get();

        foreach ($duePools as $pool) {
            $settlement->settle($pool, $params);
        }

        $this->info("Settled {$duePools->count()} Heritage pool(s).");

        return self::SUCCESS;
    }
}
