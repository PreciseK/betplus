<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\BackOffice\Reporting\FinancialReportService;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Story 6.9 / REQ-BO-023 — runs the report off the request path and writes CSV to the
 * local private disk. The reportExport row this reads its filter from is also the
 * audit record (actor/filter/row count) — see the migration's doc comment; nothing
 * further needs writing once this job finishes.
 */
class GenerateFinancialReportExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DOWNLOAD_TTL_HOURS = 72;

    public function __construct(public readonly int $reportExportId)
    {
    }

    public function handle(FinancialReportService $reports): void
    {
        $export = ReportExport::find($this->reportExportId);
        if ($export === null || $export->status !== 'queued') {
            return;
        }

        try {
            $filter = $export->filter;
            $report = $reports->generate(
                CarbonImmutable::parse($filter['from']),
                CarbonImmutable::parse($filter['to']),
                $filter['game_code'] ?? null,
                $filter['state_code'] ?? null,
            );

            $path = 'reports/' . $export->id . '-' . now()->format('Ymd_His') . '.csv';
            Storage::disk('local')->put($path, $this->toCsv($report));

            $export->forceFill([
                'status' => 'completed',
                'filePath' => $path,
                'rowCount' => count($report['rows']),
                'expiresAt' => now()->addHours(self::DOWNLOAD_TTL_HOURS),
                'completedAt' => now(),
            ])->save();
        } catch (Throwable $e) {
            $export->forceFill([
                'status' => 'failed',
                'failureReason' => substr($e->getMessage(), 0, 500),
                'completedAt' => now(),
            ])->save();

            throw $e;
        }
    }

    /** @param array{rows: list<array<string, mixed>>, withdrawals: array<string, mixed>, floatMovementsByState: list<array<string, mixed>>} $report */
    private function toCsv(array $report): string
    {
        $fh = fopen('php://temp', 'r+');

        fputcsv($fh, ['section', 'gameCode', 'stateCode', 'ticketCount', 'stakesKobo', 'winningTicketCount', 'grossPrizesKobo', 'netPrizesKobo', 'taxWithheldKobo', 'rtpActualBasisPoints', 'rtpModelledBasisPoints', 'ggrKobo', 'providerFeesKobo', 'ggrLevyKobo']);
        foreach ($report['rows'] as $row) {
            fputcsv($fh, [
                'game_state', $row['gameCode'], $row['stateCode'], $row['ticketCount'], $row['stakesKobo'],
                $row['winningTicketCount'], $row['grossPrizesKobo'], $row['netPrizesKobo'], $row['taxWithheldKobo'],
                $row['rtpActualBasisPoints'], $row['rtpModelledBasisPoints'], $row['ggrKobo'],
                $row['providerFeesKobo'], $row['ggrLevyKobo'],
            ]);
        }

        fputcsv($fh, []);
        fputcsv($fh, ['section', 'requestedCount', 'requestedKobo', 'confirmedCount', 'confirmedKobo']);
        fputcsv($fh, ['withdrawals', $report['withdrawals']['requestedCount'], $report['withdrawals']['requestedKobo'], $report['withdrawals']['confirmedCount'], $report['withdrawals']['confirmedKobo']]);

        fputcsv($fh, []);
        fputcsv($fh, ['section', 'stateCode', 'creditedKobo', 'debitedKobo', 'netKobo']);
        foreach ($report['floatMovementsByState'] as $row) {
            fputcsv($fh, ['float_movement', $row['stateCode'], $row['creditedKobo'], $row['debitedKobo'], $row['netKobo']]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }
}
