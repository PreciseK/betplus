"""The whole Heritage engine. A pure function of (seed, stake, prize_table,
player_input): no I/O, no database, no internally generated randomness
(REQ-GEC-001..003) — verified by tests/test_purity.py, mirroring
tests/Unit/EnginePurityTest.php on the PHP side.
"""

from __future__ import annotations

from engine_heritage import board as boardmod
from engine_heritage import digest as digestmod
from engine_heritage import tiers as tiersmod
from engine_heritage.models import EngineState, PlayerInput, PrizeTierIn, ResolveResponse

ENGINE_VERSION = "heritage-1.0.0"

# Counter ranges consumed by each deterministic draw, kept apart so composing them
# never collides (see seed.draw_without_replacement's per-call counter contract).
_TIER_COUNTER = 0
_MATCH_COUNTER = 1
_BOARD_COUNTER_START = 100  # reserves 100-108 (9 draws)
_WINNER_COUNTER_START = 200  # reserves 200-204 (up to 5 draws)

SECOND_CHANCE_STAKE_RATIO_BASIS_POINTS = 1_000  # REQ-HG-030 — 10% of stake, sc_stake_ratio default 0.10


def resolve(
    ticket_id: str,
    seed_hex: str,
    stake_kobo: int,
    prize_table: list[PrizeTierIn],
    player_input: PlayerInput,
) -> ResolveResponse:
    tiers = [
        tiersmod.PrizeTier(t.name, t.probability_basis_points, t.multiplier_hundredths, t.outcome_type)
        for t in sorted(prize_table, key=lambda t: t.name)  # canonical order — see models.py doc comment
    ]
    tiersmod.validate_prize_table(tiers)

    seed = bytes.fromhex(seed_hex)

    tier = tiersmod.draw_tier(seed, _TIER_COUNTER, tiers)
    match_count = tiersmod.draw_match_count(seed, _MATCH_COUNTER, tier.name)
    board = boardmod.draw_board(seed, _BOARD_COUNTER_START)
    winning_positions = boardmod.assign_winning_positions(
        seed, _WINNER_COUNTER_START, player_input.selected_positions, match_count
    )

    if tier.outcome_type == "cash":
        gross_prize_kobo = (stake_kobo * tier.multiplier_hundredths) // 100
        second_chance_stake_kobo = None
    elif tier.outcome_type == "draw_entry":
        gross_prize_kobo = 0
        second_chance_stake_kobo = (stake_kobo * SECOND_CHANCE_STAKE_RATIO_BASIS_POINTS) // 10_000
    else:
        gross_prize_kobo = 0
        second_chance_stake_kobo = None

    digest = digestmod.of(seed_hex, player_input.selected_positions, board, winning_positions, tier.name)

    return ResolveResponse(
        ticket_id=ticket_id,
        outcome_tier=tier.name,
        gross_prize_kobo=gross_prize_kobo,
        engine_state=EngineState(
            board=board,
            winning_positions=winning_positions,
            selected_positions=list(player_input.selected_positions),
            match_count=match_count,
            tradition=player_input.tradition,
            leader_type=player_input.leader_type,
            second_chance_stake_kobo=second_chance_stake_kobo,
        ),
        engine_version=ENGINE_VERSION,
        digest=digest,
    )


def replay(
    ticket_id: str,
    seed_hex: str,
    stake_kobo: int,
    prize_table: list[PrizeTierIn],
    player_input: PlayerInput,
) -> ResolveResponse:
    """Same inputs, byte-identical output — resolve() is already pure and
    deterministic (REQ-GEC-001), so replay just names the intent at the call site,
    exactly like BlackRedEngine::replay()."""
    return resolve(ticket_id, seed_hex, stake_kobo, prize_table, player_input)


def describe() -> dict:
    return {
        "engine": "heritage",
        "engine_version": ENGINE_VERSION,
        "game_code": "HERITAGE",
        "board_size": boardmod.BOARD_SIZE,
        "pick_size": boardmod.PICK_SIZE,
        "tier_names": sorted(tiersmod.TIER_MATCH_COUNTS.keys()),
    }
