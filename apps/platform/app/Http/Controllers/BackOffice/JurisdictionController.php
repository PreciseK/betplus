<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\BackOffice\UpsertStateLicenceRequest;
use App\Models\AuditLog;
use App\Models\InstitutionUser;
use App\Models\StateLicence;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

/**
 * Story 6.7 (REQ-BO-010) — per state: licence status/expiry, ruleset version, tax
 * rates, activity volume, remittance status. Tax rates come from config/tax.php
 * (the same source TaxEngine reads — Epic 6's back-office UI for CHANGING them via
 * maker-checker, REQ-TAX-011, isn't built; this console is read plus licence upsert).
 */
class JurisdictionController extends Controller
{
    /** GET /backoffice/v1/jurisdictions */
    public function index(): JsonResponse
    {
        $licences = StateLicence::orderBy('stateCode')->get();

        return response()->json([
            'states' => $licences->map(function (StateLicence $l) {
                $daysToExpiry = (int) now()->diffInDays($l->expiresAt, false);
                $activityVolume = Ticket::where('stateCode', $l->stateCode)->count();

                return [
                    'state_code' => $l->stateCode,
                    'licence_number' => $l->licenceNumber,
                    'issued_at' => $l->issuedAt->toDateString(),
                    'expires_at' => $l->expiresAt->toDateString(),
                    'is_expired' => $l->expiresAt->isPast(),
                    // REQ-NFR-043 — expiry within 60 days raises an alert.
                    'expiry_alert' => !$l->expiresAt->isPast() && $daysToExpiry <= 60,
                    'ruleset_version' => $l->rulesetVersion,
                    'remittance_status' => $l->remittanceStatus,
                    'activity_volume' => $activityVolume,
                    'resident_wht_rate_basis_points' => (int) config('tax.withholding.resident_rate_basis_points'),
                    'non_resident_wht_rate_basis_points' => (int) config('tax.withholding.non_resident_rate_basis_points'),
                ];
            })->values(),
        ]);
    }

    /** POST /backoffice/v1/jurisdictions — create or update one state's licence record, applies immediately (no maker-checker gate). */
    public function upsert(UpsertStateLicenceRequest $request): JsonResponse
    {
        $stateCode = $request->string('state_code')->toString();
        $before = StateLicence::where('stateCode', $stateCode)->first()?->getAttributes();

        $licence = StateLicence::updateOrCreate(
            ['stateCode' => $stateCode],
            [
                'licenceNumber' => $request->string('licence_number')->toString(),
                'issuedAt' => $request->string('issued_at')->toString(),
                'expiresAt' => $request->string('expires_at')->toString(),
                'rulesetVersion' => $request->string('ruleset_version')->toString(),
                'remittanceStatus' => $request->string('remittance_status')->toString(),
            ],
        );

        /** @var InstitutionUser $actor */
        $actor = $request->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => 'jurisdiction_licence_upserted',
            'targetTable' => 'stateLicence',
            'targetId' => $licence->id,
            'before' => $before,
            'after' => $licence->getAttributes(),
            'ipAddress' => $request->ip(),
            'userAgent' => $request->userAgent(),
        ]);

        return response()->json(['state_code' => $licence->stateCode, 'expires_at' => $licence->expiresAt->toDateString()]);
    }
}
