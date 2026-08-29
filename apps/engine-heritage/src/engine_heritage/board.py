"""Board generation and the consistent-reveal winning-position assignment (REQ-HG-010,
REQ-HG-011).

Positions are 0-8 (nine tiles). REQ-HG-010 draws the board (9 distinct 1-90 integers)
and a match_count from the tier independently of the player's pick; REQ-HG-011 then
needs exactly match_count of the player's 5 chosen positions to land inside a 5-of-9
Winning Set. Those two are only reconcilable if the Winning Set is constructed FROM
the player's selection: of its 5 winning positions, match_count come from the player's
own 5 picks and the remaining (5 - match_count) come from the other 4 positions. That
split is what makes 0 matches structurally impossible (5 - match_count <= 4 forces
match_count >= 1) — the fact PRD §9.3's reference combinatorics calls out explicitly.
"""

from __future__ import annotations

from dataclasses import dataclass

from engine_heritage import seed as seedmod

BOARD_SIZE = 9
PICK_SIZE = 5


@dataclass(frozen=True)
class RevealAssignment:
    board: list[int]  # position i holds board[i], a number 1-90
    winning_positions: list[int]  # 5 of 0-8


def draw_board(seed: bytes, start_counter: int) -> list[int]:
    """9 distinct integers from 1-90, one per position, in position order."""
    return seedmod.draw_without_replacement(seed, start_counter, list(range(1, 91)), BOARD_SIZE)


def assign_winning_positions(
    seed: bytes, start_counter: int, selected_positions: list[int], match_count: int
) -> list[int]:
    """Which 5 of the 9 positions are winners, constructed so that exactly
    match_count of them are inside selected_positions (REQ-HG-011)."""
    if len(selected_positions) != PICK_SIZE or len(set(selected_positions)) != PICK_SIZE:
        raise ValueError("selected_positions must be exactly 5 distinct positions.")
    if any(p < 0 or p >= BOARD_SIZE for p in selected_positions):
        raise ValueError("selected_positions must be within 0-8.")

    complement = [p for p in range(BOARD_SIZE) if p not in selected_positions]
    non_winner_count = PICK_SIZE - match_count
    if non_winner_count > len(complement):
        # Structurally impossible given a 5-of-9 pick and a 4-position complement —
        # a caller passing an out-of-range match_count is a programming error, not
        # untrusted input (match_count always comes from tiers.draw_match_count()).
        raise ValueError(f"match_count {match_count} is not achievable against a 5-of-9 pick.")

    winners_from_pick = seedmod.draw_without_replacement(seed, start_counter, selected_positions, match_count)
    winners_from_rest = seedmod.draw_without_replacement(
        seed, start_counter + match_count, complement, non_winner_count
    )
    return sorted(winners_from_pick + winners_from_rest)
