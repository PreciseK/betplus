import os
from collections import Counter

import pytest

from engine_heritage import tiers as tiersmod


def _launch_tiers() -> list[tiersmod.PrizeTier]:
    """Launch table: 5 of 5 is Jackpot (25x), 4 of 5 is High (0.5x half stake back), 1-3 is Loss."""
    return [
        tiersmod.PrizeTier("TIER_JACKPOT", 200, 2_500, "cash"),
        tiersmod.PrizeTier("TIER_HIGH", 600, 50, "cash"),
        tiersmod.PrizeTier("TIER_LOSS", 9_200, 0, "none"),
    ]


def test_validate_prize_table_accepts_the_launch_table() -> None:
    tiersmod.validate_prize_table(_launch_tiers())  # does not raise


def test_validate_prize_table_rejects_a_missing_tier() -> None:
    tiers = _launch_tiers()[:-1]
    with pytest.raises(ValueError):
        tiersmod.validate_prize_table(tiers)


def test_validate_prize_table_rejects_probabilities_not_summing_to_10000() -> None:
    tiers = _launch_tiers()
    tiers[0] = tiersmod.PrizeTier("TIER_JACKPOT", 999, 2_500, "cash")
    with pytest.raises(ValueError):
        tiersmod.validate_prize_table(tiers)


def test_draw_tier_is_deterministic() -> None:
    seed = os.urandom(32)
    tiers = _launch_tiers()
    assert tiersmod.draw_tier(seed, 0, tiers).name == tiersmod.draw_tier(seed, 0, tiers).name


def test_draw_tier_respects_weighting_over_many_draws() -> None:
    tiers = _launch_tiers()
    counts: Counter[str] = Counter()
    for i in range(20_000):
        seed = i.to_bytes(32, "big")
        counts[tiersmod.draw_tier(seed, 0, tiers).name] += 1

    # Loose tolerance — this asserts the weighting is roughly right, not RNG-lab precision.
    assert 100 < counts["TIER_JACKPOT"] < 700
    assert 900 < counts["TIER_HIGH"] < 2_100
    assert 15_000 < counts["TIER_LOSS"] < 20_000


def test_draw_match_count_returns_the_fixed_value_for_single_option_tiers() -> None:
    seed = os.urandom(32)
    assert tiersmod.draw_match_count(seed, 1, "TIER_JACKPOT") == 5
    assert tiersmod.draw_match_count(seed, 1, "TIER_HIGH") == 4


def test_draw_match_count_for_tier_loss_is_always_1_to_3() -> None:
    for i in range(200):
        seed = i.to_bytes(32, "big")
        assert tiersmod.draw_match_count(seed, 1, "TIER_LOSS") in (1, 2, 3)
