<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Ussd\UssdIdentityService;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\UssdSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 8.1/8.2 (REQ-USSD-003, REQ-ID-004) — the internal surface apps/ussd calls.
 * NOT the telco aggregator's own webhook target; apps/ussd owns that (it "holds no
 * database access" per its own composer.json doc comment) and calls in here to (a)
 * bootstrap identity without an OTP round trip and (b) mirror turn state for audit,
 * since it can't write ussdSession directly itself. Real money/game operations never
 * come through here — those go through /v1 with the token identify()/registerComplete()
 * return, the same surface web and app already use.
 */
class UssdGatewayController extends Controller
{
    public function __construct(private readonly UssdIdentityService $identity)
    {
    }

    /** POST /internal/ussd/identify {msisdn} */
    public function identify(Request $request): JsonResponse
    {
        return response()->json($this->identity->identify((string) $request->input('msisdn')));
    }

    /** POST /internal/ussd/register/complete {msisdn} */
    public function registerComplete(Request $request): JsonResponse
    {
        return response()->json($this->identity->completeRegistration((string) $request->input('msisdn')));
    }

    /** POST /internal/ussd/session {session_id, msisdn, screen, input_text, status} — REQ-USSD-003's audit mirror. */
    public function sessionSync(Request $request): JsonResponse
    {
        $msisdn = (string) $request->input('msisdn');
        $player = Player::where('msisdn', $msisdn)->first();

        $session = UssdSession::updateOrCreate(
            ['sessionId' => (string) $request->input('session_id'), 'msisdn' => $msisdn],
            [
                'playerId' => $player?->id,
                'screen' => (string) $request->input('screen'),
                // Never a PIN/OTP value — apps/ussd is responsible for not sending one;
                // this endpoint additionally caps length so a mistake can't flood the row.
                'inputText' => substr((string) $request->input('input_text', ''), 0, 200),
                'status' => (string) $request->input('status', 'active'),
                'lastTurnAt' => now(),
            ],
        );

        return response()->json(['id' => $session->id]);
    }
}
