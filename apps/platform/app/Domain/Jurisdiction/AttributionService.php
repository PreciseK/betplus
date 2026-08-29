<?php

declare(strict_types=1);

namespace App\Domain\Jurisdiction;

use App\Domain\Analytics\AnalyticsEventRecorder;
use App\Domain\Jurisdiction\Signals\LocationSignal;
use App\Domain\Jurisdiction\Signals\LocationSignalProvider;
use App\Domain\Ticket\TicketEligibilityException;
use App\Models\ExclusionRegistry;
use App\Models\GameRegistry;
use App\Models\Player;
use App\Models\StateLicence;

/**
 * §7.7 — resolves and gates state attribution for one ticket (REQ-GEO-001..007). Fails
 * closed at every step: low confidence, an unlicensed state, or detected VPN/proxy all
 * refuse ticket creation rather than guessing (REQ-GEO-004..006).
 *
 * Per-player registry exclusion (REQ-RG-011..016) is Epic 5 and is NOT checked here —
 * only the state-level "does this state have a registry configured at all" gate from
 * Story 3.5 is. See exclusionRegistry migration's doc comment.
 */
final class AttributionService
{
    public function __construct(
        private readonly LocationSignalProvider $signalProvider,
        private readonly AnalyticsEventRecorder $analytics,
    ) {
    }

    /** @return array{stateCode:string, confidence:float, rulesetVersion:string} */
    public function attribute(Player $player, GameRegistry $game): array
    {
        $signal = $this->signalProvider->resolve($player);

        if ($signal->vpnOrProxyDetected) {
            // REQ-GEO-006 also flags the account; account-flagging (Epic 5/6 review
            // queue) doesn't exist yet, so this refuses play without flagging for now.
            throw new TicketEligibilityException('VPN_OR_PROXY_DETECTED', 'VPN or proxy detected; play blocked.');
        }

        $minConfidence = (float) config('jurisdiction.min_confidence');
        if ($signal->confidence < $minConfidence) {
            throw new TicketEligibilityException('LOCATION_UNVERIFIED', 'Location confidence below the required threshold.');
        }

        $enabledStates = $game->enabledStates;
        if (!in_array($signal->stateCode, $enabledStates, true)) {
            throw new TicketEligibilityException('STATE_NOT_LICENSED', "State {$signal->stateCode} is outside the active licence footprint.");
        }

        // Story 3.5's licensing precondition: a state only enters the footprint once an
        // exclusion registry is configured for it. Belt-and-braces re-check here in
        // case gameRegistry.enabledStates and exclusionRegistry ever drift.
        if (!ExclusionRegistry::where('stateCode', $signal->stateCode)->exists()) {
            throw new TicketEligibilityException('STATE_NOT_LICENSED', "No exclusion registry configured for {$signal->stateCode}.");
        }

        // Story 6.7 / REQ-QA-017 — "play in that state stops at expiry rather than the
        // next deployment." gameRegistry.enabledStates is a static config array; this
        // is the check that actually reads the clock on every request, so a lapsed
        // licence bites immediately rather than waiting for someone to edit that array.
        $licence = StateLicence::where('stateCode', $signal->stateCode)->first();
        if ($licence === null || $licence->expiresAt->isPast()) {
            throw new TicketEligibilityException('STATE_NOT_LICENSED', "No current licence on file for {$signal->stateCode}.");
        }

        // Story 6.10 — geo-attribution funnel step (REQ-ANL-007). Only reached once every
        // gate above has passed, so this is "attributed and cleared to play", not just
        // "location resolved".
        $this->analytics->record('state_attributed', $player, 'web', gameCode: $game->gameCode, stateCode: $signal->stateCode);

        return [
            'stateCode' => $signal->stateCode,
            'confidence' => $signal->confidence,
            'rulesetVersion' => (string) config('jurisdiction.ruleset_version', '2026.1'),
        ];
    }
}
