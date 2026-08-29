"""Deterministic byte/int stream from a platform-issued seed.

REQ-RNG-008 prohibits the `random` module anywhere on this outcome path — this module
is what the Heritage engine uses instead, mirroring apps/platform's
Domain/Games/Engine/BlackRed/DeckDraw.php exactly: HMAC-SHA256 keyed by the seed,
counter as the message. Same technique, same rationale (HMAC expansion of a CSPRNG
seed is not one of the banned PRNG calls, and is what REQ-RNG-001 actually asks for),
ported to Python rather than reinvented.
"""

from __future__ import annotations

import hashlib
import hmac


def digest_at(seed: bytes, counter: int) -> bytes:
    """32 deterministic bytes for this (seed, counter) pair."""
    return hmac.new(seed, str(counter).encode("ascii"), hashlib.sha256).digest()


def _int_below(seed: bytes, counter: int, n: int) -> int:
    """A deterministic integer in [0, n). Simple modulo, not rejection-sampled.

    ponytail: modulo introduces a negligible bias for n not dividing 2**256 evenly —
    at n <= 90 that bias is astronomically smaller than anything a statistical RNG
    audit would flag, and it matches this codebase's existing precedent (DeckDraw.php
    extracts a single bit via `ord(digest[0]) & 1` rather than rejection sampling).
    Upgrade path: rejection sampling, if REQ-RNG-006's chi-square monitoring ever
    flags this specific draw.
    """
    if n <= 0:
        raise ValueError("n must be positive")
    return int.from_bytes(digest_at(seed, counter), "big") % n


def draw_without_replacement(seed: bytes, start_counter: int, population: list[int], k: int) -> list[int]:
    """k distinct items from population, in draw order. Consumes k counters starting
    at start_counter (so callers can reserve a counter range and stay deterministic
    even when composed with other draws)."""
    if k > len(population):
        raise ValueError("k exceeds population size")
    pool = list(population)
    drawn: list[int] = []
    for i in range(k):
        idx = _int_below(seed, start_counter + i, len(pool))
        drawn.append(pool.pop(idx))
    return drawn


def choose_index(seed: bytes, counter: int, n: int) -> int:
    """A single deterministic index in [0, n)."""
    return _int_below(seed, counter, n)
