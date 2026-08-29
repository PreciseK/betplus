import os

from engine_heritage import engine as enginemod
from engine_heritage.models import PlayerInput, PrizeTierIn

LAUNCH_TABLE = [
    PrizeTierIn(name="TIER_JACKPOT", probability_basis_points=200, multiplier_hundredths=2_500, outcome_type="cash"),
    PrizeTierIn(name="TIER_HIGH", probability_basis_points=600, multiplier_hundredths=500, outcome_type="cash"),
    PrizeTierIn(name="TIER_SECOND_CHANCE", probability_basis_points=1_600, multiplier_hundredths=0, outcome_type="draw_entry"),
    PrizeTierIn(name="TIER_LOSS", probability_basis_points=7_600, multiplier_hundredths=0, outcome_type="none"),
]


def _player_input() -> PlayerInput:
    return PlayerInput(selected_positions=[0, 1, 2, 3, 4], tradition="yoruba", leader_type="king")


def test_resolve_is_deterministic_given_identical_inputs() -> None:
    seed_hex = os.urandom(32).hex()
    a = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
    b = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
    assert a == b


def test_replay_is_byte_identical_to_resolve() -> None:
    seed_hex = os.urandom(32).hex()
    resolved = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
    replayed = enginemod.replay("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
    assert resolved == replayed


def test_resolve_output_satisfies_the_boards_structural_invariants() -> None:
    for i in range(200):
        seed_hex = i.to_bytes(32, "big").hex()
        result = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
        state = result.engine_state

        assert len(state.board) == 9
        assert len(set(state.board)) == 9
        assert all(1 <= n <= 90 for n in state.board)
        assert len(state.winning_positions) == 5

        overlap = set(state.winning_positions) & set(state.selected_positions)
        assert len(overlap) == state.match_count
        assert state.match_count >= 1  # REQ-HG-014 — zero matches is impossible


def test_a_cash_tier_computes_gross_prize_from_the_multiplier() -> None:
    # Search seeds until a TIER_JACKPOT (25x) lands, to check the actual arithmetic.
    for i in range(5_000):
        seed_hex = i.to_bytes(32, "big").hex()
        result = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
        if result.outcome_tier == "TIER_JACKPOT":
            assert result.gross_prize_kobo == 100_000 * 2_500 // 100  # 25x
            assert result.engine_state.second_chance_stake_kobo is None
            return
    raise AssertionError("no TIER_JACKPOT observed in 5000 seeds — check the weighting")


def test_a_second_chance_tier_computes_a_10_percent_entry_stake_and_no_cash_prize() -> None:
    for i in range(2_000):
        seed_hex = i.to_bytes(32, "big").hex()
        result = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
        if result.outcome_tier == "TIER_SECOND_CHANCE":
            assert result.gross_prize_kobo == 0
            assert result.engine_state.second_chance_stake_kobo == 10_000  # 10% of 100_000
            return
    raise AssertionError("no TIER_SECOND_CHANCE observed in 2000 seeds — check the weighting")


def test_digest_changes_if_the_selection_changes() -> None:
    seed_hex = os.urandom(32).hex()
    a = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
    other_input = PlayerInput(selected_positions=[4, 5, 6, 7, 8], tradition="yoruba", leader_type="king")
    b = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, other_input)
    assert a.digest != b.digest


def test_tradition_and_leader_never_affect_the_financial_outcome() -> None:
    # REQ-HG-051 — cosmetic only.
    seed_hex = os.urandom(32).hex()
    a = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, _player_input())
    other = PlayerInput(selected_positions=[0, 1, 2, 3, 4], tradition="igbo", leader_type="queen")
    b = enginemod.resolve("t1", seed_hex, 100_000, LAUNCH_TABLE, other)

    assert a.outcome_tier == b.outcome_tier
    assert a.gross_prize_kobo == b.gross_prize_kobo
    assert a.engine_state.board == b.engine_state.board
    assert a.engine_state.winning_positions == b.engine_state.winning_positions
