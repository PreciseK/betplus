"""FastAPI wiring for the Engine Contract's three endpoints (PRD §6.2). Everything
that isn't request parsing/response shaping lives in engine.py, which has zero
FastAPI/pydantic-runtime imports beyond the plain dataclasses/type hints needed for
its own inputs — kept importable and testable with no HTTP server running at all.

REQ-HOST-004 / REQ-SEC-011 — this runs under `uvicorn --workers N` behind Nginx,
loopback-bound, never exposed publicly; see apps/engine-heritage/README.md for the
run command and architecture.md's mTLS note for the still-open production trust-
boundary work (not built here — see that README for what's deferred and why).
"""

from __future__ import annotations

from fastapi import FastAPI, HTTPException

from engine_heritage import engine as enginemod
from engine_heritage.models import DescribeResponse, ResolveRequest, ResolveResponse

app = FastAPI(title="engine-heritage", version=enginemod.ENGINE_VERSION)


@app.post("/engine/v1/resolve", response_model=ResolveResponse)
def resolve(request: ResolveRequest) -> ResolveResponse:
    try:
        return enginemod.resolve(
            request.ticket_id, request.seed, request.stake_kobo, request.prize_table, request.player_input
        )
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


@app.post("/engine/v1/replay", response_model=ResolveResponse)
def replay(request: ResolveRequest) -> ResolveResponse:
    try:
        return enginemod.replay(
            request.ticket_id, request.seed, request.stake_kobo, request.prize_table, request.player_input
        )
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


@app.get("/engine/v1/describe", response_model=DescribeResponse)
def describe() -> dict:
    return enginemod.describe()
