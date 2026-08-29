import os

from engine_heritage import seed as seedmod


def _seed() -> bytes:
    return os.urandom(32)


def test_digest_at_is_deterministic() -> None:
    s = _seed()
    assert seedmod.digest_at(s, 5) == seedmod.digest_at(s, 5)


def test_digest_at_differs_by_counter() -> None:
    s = _seed()
    assert seedmod.digest_at(s, 1) != seedmod.digest_at(s, 2)


def test_digest_at_differs_by_seed() -> None:
    assert seedmod.digest_at(_seed(), 1) != seedmod.digest_at(_seed(), 1)


def test_draw_without_replacement_is_distinct_and_deterministic() -> None:
    s = _seed()
    population = list(range(1, 91))
    a = seedmod.draw_without_replacement(s, 0, population, 9)
    b = seedmod.draw_without_replacement(s, 0, population, 9)
    assert a == b
    assert len(set(a)) == 9
    assert all(1 <= n <= 90 for n in a)


def test_draw_without_replacement_rejects_k_larger_than_population() -> None:
    s = _seed()
    try:
        seedmod.draw_without_replacement(s, 0, [1, 2], 3)
        assert False, "expected ValueError"
    except ValueError:
        pass


def test_choose_index_is_within_bounds() -> None:
    s = _seed()
    for counter in range(50):
        assert 0 <= seedmod.choose_index(s, counter, 9) < 9
