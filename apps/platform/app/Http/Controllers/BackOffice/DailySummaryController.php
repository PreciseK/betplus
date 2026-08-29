<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Analytics\DailySummaryService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailySummaryController extends Controller
{
    public function __construct(private readonly DailySummaryService $summary)
    {
    }

    /** GET /backoffice/v1/daily-summary?date=YYYY-MM-DD&game_code=BLACKRED */
    public function show(Request $request): JsonResponse
    {
        $date = $request->query('date');
        $day = $date ? CarbonImmutable::parse($date) : CarbonImmutable::today();

        return response()->json($this->summary->forDay($day, $request->query('game_code')));
    }
}
