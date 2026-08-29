<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\RequestFinancialReportExportRequest;
use App\Jobs\GenerateFinancialReportExportJob;
use App\Models\InstitutionUser;
use App\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Story 6.9 (REQ-BO-007/008/023) — request/status/download surface for the async
 * financial report export. The reportExport row is itself the audit record (actor,
 * filter, row count) — see the creating migration's doc comment.
 */
class ReportExportController extends Controller
{
    /** POST /backoffice/v1/reports/financial */
    public function request(RequestFinancialReportExportRequest $request): JsonResponse
    {
        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');

        $export = ReportExport::create([
            'reportType' => 'financial',
            'requestedBy' => $actor->id,
            'filter' => [
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'game_code' => $request->input('game_code'),
                'state_code' => $request->input('state_code'),
            ],
            'status' => 'queued',
        ]);

        GenerateFinancialReportExportJob::dispatch($export->id);

        return response()->json(['id' => $export->id, 'status' => $export->status], 202);
    }

    /** GET /backoffice/v1/reports/exports/{id} */
    public function show(int $id): JsonResponse
    {
        $export = ReportExport::findOrFail($id);

        return response()->json([
            'id' => $export->id,
            'report_type' => $export->reportType,
            'status' => $export->status,
            'row_count' => $export->rowCount,
            'failure_reason' => $export->failureReason,
            'download_url' => $export->status === 'completed'
                ? URL::temporarySignedRoute('backoffice.reports.download', $export->expiresAt, ['id' => $export->id])
                : null,
        ]);
    }

    /** GET /backoffice/v1/reports/exports/{id}/download — signed, expiring (REQ-BO-023). */
    public function download(Request $request, int $id): JsonResponse|StreamedResponse
    {
        if (!$request->hasValidSignature()) {
            return response()->json(['message' => 'This link has expired or is invalid.'], 403);
        }

        $export = ReportExport::findOrFail($id);
        if ($export->status !== 'completed' || $export->filePath === null) {
            return response()->json(['message' => 'Report not ready.'], 404);
        }

        return Storage::disk('local')->download($export->filePath, "financial-report-{$export->id}.csv");
    }
}
