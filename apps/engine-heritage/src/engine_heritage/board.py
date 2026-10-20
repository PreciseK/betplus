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

# Tribe+leader -> the 5 catalogue numbers that belong to it. Mirrors
# apps/platform/database/seeders/HeritageGameSeeder.php's seedCatalogue() exactly:
# items 21-90, 5 per tribe+leader, in HeritageRegaliaCatalogueData::items() order.
# There is no shared source of truth for this — the engine deliberately holds no
# database (REQ-GEC-002) — so if that seeding ever changes, this map must be
# updated by hand to match. test_board.py asserts it is internally consistent
# (70 numbers, 21-90, no gaps or overlaps), which catches a typo here but not a
# drift from the Laravel side.
TRIBE_LEADER_NUMBERS: dict[tuple[str, str], list[int]] = {
    ("yoruba", "king"): [21, 22, 23, 24, 25],
    ("yoruba", "queen"): [26, 27, 28, 29, 30],
    ("igbo", "king"): [31, 32, 33, 34, 35],
    ("igbo", "queen"): [36, 37, 38, 39, 40],
    ("hausa_fulani", "king"): [41, 42, 43, 44, 45],
    ("hausa_fulani", "queen"): [46, 47, 48, 49, 50],
    ("edo", "king"): [51, 52, 53, 54, 55],
    ("edo", "queen"): [56, 57, 58, 59, 60],
    ("efik_ibibio", "king"): [61, 62, 63, 64, 65],
    ("efik_ibibio", "queen"): [66, 67, 68, 69, 70],
    ("ijaw", "king"): [71, 72, 73, 74, 75],
    ("ijaw", "queen"): [76, 77, 78, 79, 80],
    ("middle_belt", "king"): [81, 82, 83, 84, 85],
    ("middle_belt", "queen"): [86, 87, 88, 89, 90],
}


@dataclass(frozen=True)
class RevealAssignment:
    board: list[int]  # position i holds board[i], a number 1-90
    winning_positions: list[int]  # 5 of 0-8


def draw_board(
    seed: bytes,
    start_counter: int,
    *,
    winning_positions: list[int] | None = None,
    tradition: str | None = None,
    leader_type: str | None = None,
) -> list[int]:
    """9 distinct integers from 1-90, one per position, in position order.

    When winning_positions is given and (tradition, leader_type) resolve to a
    known tribe+leader, the 5 catalogue numbers belonging to that tribe+leader
    are placed exactly on the winning positions, and the remaining 4 positions
    get random decoy numbers from the other 85. This never touches odds —
    winning_positions is already fully determined by assign_winning_positions()
    before this runs, purely from the tier and the player's own picks, so which
    regalia is shown stays cosmetic (REQ-HG-051) even though it's no longer
    independent of the winning set. Falls back to the original fully-random,
    tradition-agnostic draw when winning_positions is omitted or the tribe is
    unrecognized, so every other caller (e.g. the unit tests below, and any
    ticket for a tradition this map doesn't know) is unaffected.
    """
    authentic = TRIBE_LEADER_NUMBERS.get((tradition or "", leader_type or ""))
    if winning_positions is None or authentic is None:
        return seedmod.draw_without_replacement(seed, start_counter, list(range(1, 91)), BOARD_SIZE)

    decoy_pool = [n for n in range(1, 91) if n not in authentic]
    shuffled_authentic = seedmod.draw_without_replacement(seed, start_counter, authentic, len(authentic))
    decoys = seedmod.draw_without_replacement(
        seed, start_counter + len(authentic), decoy_pool, BOARD_SIZE - len(authentic)
    )

    sorted_winners = sorted(set(winning_positions))
    non_winning_positions = [p for p in range(BOARD_SIZE) if p not in sorted_winners]
    board = [0] * BOARD_SIZE
    for position, number in zip(sorted_winners, shuffled_authentic):
        board[position] = number
    for position, number in zip(non_winning_positions, decoys):
        board[position] = number
    return board


def draw_pool_winning_positions(seed: bytes, start_counter: int) -> list[int]:
    """Model 4 (pari-mutuel pool) — the one shared 5-of-9 winning combination every
    pooled ticket in a draw window is compared against, drawn independent of any
    player's own pick. Unlike assign_winning_positions() (used by the instant-settle
    resolve() path), this never manufactures a guaranteed-match winning set from a
    specific selection — a real shared draw can legitimately leave some, or all,
    pooled tickets with zero matches, which is exactly the pari-mutuel shape."""
    return sorted(seedmod.draw_without_replacement(seed, start_counter, list(range(BOARD_SIZE)), PICK_SIZE))


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
