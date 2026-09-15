"""Engine Contract request/response shapes (PRD §6.2), Heritage-specific fields under
`player_input` / `engine_state` as the contract's own schema allows ("...game
specific...", "...for display and audit...").

The prize table's tier rows travel inline in the request rather than being looked up
by prize_table_version alone: the engine holds no database (REQ-GEC-002), so it cannot
resolve a version number to tiers itself — mirrors BlackRedEngine::resolve() already
taking `array $tiers` as a real parameter rather than a bare version number.
"""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field, field_validator

from engine_heritage.tiers import TIER_MATCH_COUNTS


class PrizeTierIn(BaseModel):
    name: str
    probability_basis_points: int = Field(ge=0, le=10_000)
    multiplier_hundredths: int = Field(ge=0)
    outcome_type: Literal["cash", "draw_entry", "none"]


class PlayerInput(BaseModel):
    selected_positions: list[int] = Field(min_length=5, max_length=5)
    tradition: str
    leader_type: Literal["king", "queen"]

    @field_validator("selected_positions")
    @classmethod
    def positions_are_distinct_and_in_range(cls, v: list[int]) -> list[int]:
        if len(set(v)) != 5:
            raise ValueError("selected_positions must be 5 distinct positions.")
        if any(p < 0 or p > 8 for p in v):
            raise ValueError("selected_positions must each be within 0-8.")
        return v


class ResolveRequest(BaseModel):
    ticket_id: str
    game_code: Literal["HERITAGE"]
    stake_kobo: int = Field(gt=0)
    prize_table_version: str
    prize_table: list[PrizeTierIn]
    seed: str
    player_input: PlayerInput

    @field_validator("prize_table")
    @classmethod
    def tiers_cover_the_canonical_set(cls, v: list[PrizeTierIn]) -> list[PrizeTierIn]:
        names = {t.name for t in v}
        if names != set(TIER_MATCH_COUNTS.keys()):
            raise ValueError(f"prize_table must contain exactly {sorted(TIER_MATCH_COUNTS)}.")
        total = sum(t.probability_basis_points for t in v)
        if total != 10_000:
            raise ValueError(f"prize_table probabilities must sum to 10000 basis points, got {total}.")
        return v

    @field_validator("seed")
    @classmethod
    def seed_is_hex(cls, v: str) -> str:
        try:
            bytes.fromhex(v)
        except ValueError as exc:
            raise ValueError("seed must be hex-encoded.") from exc
        return v


class EngineState(BaseModel):
    board: list[int]
    winning_positions: list[int]
    selected_positions: list[int]
    match_count: int
    tradition: str
    leader_type: str
    second_chance_stake_kobo: int | None


class ResolveResponse(BaseModel):
    ticket_id: str
    outcome_tier: str
    gross_prize_kobo: int
    engine_state: EngineState
    engine_version: str
    digest: str


class DrawPoolRequest(BaseModel):
    """Model 4 (pari-mutuel pool) — no ticket_id, stake, prize_table, or
    player_input; a pool draw has no single ticket or player to be about."""

    seed: str

    @field_validator("seed")
    @classmethod
    def seed_is_hex(cls, v: str) -> str:
        try:
            bytes.fromhex(v)
        except ValueError as exc:
            raise ValueError("seed must be hex-encoded.") from exc
        return v


class DrawPoolResponse(BaseModel):
    winning_positions: list[int]
    engine_version: str


class DescribeResponse(BaseModel):
    engine: str
    engine_version: str
    game_code: str
    board_size: int
    pick_size: int
    tier_names: list[str]
    modelled_rtp_basis_points_by_tier: dict[str, int] | None = None
