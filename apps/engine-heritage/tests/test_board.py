import os

import pytest

from engine_heritage import board as boardmod


def test_draw_board_returns_9_distinct_numbers_in_range() -> None:
    seed = os.urandom(32)
    b = boardmod.draw_board(seed, 100)
    assert len(b) == 9
    assert len(set(b)) == 9
    assert all(1 <= n <= 90 for n in b)


def test_draw_board_is_deterministic() -> None:
    seed = os.urandom(32)
    assert boardmod.draw_board(seed, 100) == boardmod.draw_board(seed, 100)


@pytest.mark.parametrize("match_count", [1, 2, 3, 4, 5])
def test_assign_winning_positions_hits_exactly_match_count_of_the_pick(match_count: int) -> None:
    seed = os.urandom(32)
    selected = [0, 1, 2, 3, 4]
    winners = boardmod.assign_winning_positions(seed, 200, selected, match_count)

    assert len(winners) == 5
    assert len(set(winners)) == 5
    overlap = set(winners) & set(selected)
    assert len(overlap) == match_count


def test_assign_winning_positions_is_deterministic_given_the_same_selection() -> None:
    seed = os.urandom(32)
    selected = [0, 2, 4, 6, 8]
    a = boardmod.assign_winning_positions(seed, 200, selected, 3)
    b = boardmod.assign_winning_positions(seed, 200, selected, 3)
    assert a == b


def test_assign_winning_positions_rejects_a_non_5_selection() -> None:
    seed = os.urandom(32)
    with pytest.raises(ValueError):
        boardmod.assign_winning_positions(seed, 200, [0, 1, 2], 1)


def test_assign_winning_positions_rejects_duplicate_positions() -> None:
    seed = os.urandom(32)
    with pytest.raises(ValueError):
        boardmod.assign_winning_positions(seed, 200, [0, 0, 1, 2, 3], 1)


def test_zero_matches_is_structurally_unreachable() -> None:
    # REQ-HG-014 — with a 5-of-9 pick and a 4-position complement, 5 winners can
    # never be constructed with 0 of them inside the pick (max non-pick winners is 4).
    seed = os.urandom(32)
    with pytest.raises(ValueError):
        boardmod.assign_winning_positions(seed, 200, [0, 1, 2, 3, 4], 0)
