import os

from fastapi.testclient import TestClient

from engine_heritage.app import app

client = TestClient(app)

LAUNCH_TABLE = [
    {"name": "TIER_JACKPOT", "probability_basis_points": 200, "multiplier_hundredths": 2_500, "outcome_type": "cash"},
    {"name": "TIER_HIGH", "probability_basis_points": 600, "multiplier_hundredths": 50, "outcome_type": "cash"},
    {"name": "TIER_LOSS", "probability_basis_points": 9_200, "multiplier_hundredths": 0, "outcome_type": "none"},
]


def _request_body() -> dict:
    return {
        "ticket_id": "t1",
        "game_code": "HERITAGE",
        "stake_kobo": 100_000,
        "prize_table_version": "HG-NG-2026.1",
        "prize_table": LAUNCH_TABLE,
        "seed": os.urandom(32).hex(),
        "player_input": {"selected_positions": [0, 1, 2, 3, 4], "tradition": "yoruba", "leader_type": "king"},
    }


def test_describe_declares_the_engine_contract() -> None:
    response = client.get("/engine/v1/describe")
    assert response.status_code == 200
    body = response.json()
    assert body["game_code"] == "HERITAGE"
    assert body["board_size"] == 9
    assert body["pick_size"] == 5
    assert sorted(body["tier_names"]) == ["TIER_HIGH", "TIER_JACKPOT", "TIER_LOSS"]


def test_resolve_returns_a_structurally_valid_outcome() -> None:
    response = client.post("/engine/v1/resolve", json=_request_body())
    assert response.status_code == 200
    body = response.json()
    assert body["ticket_id"] == "t1"
    assert len(body["engine_state"]["board"]) == 9
    assert len(set(body["engine_state"]["board"])) == 9
    assert len(body["engine_state"]["winning_positions"]) == 5


def test_replay_is_byte_identical_to_resolve() -> None:
    body = _request_body()
    resolved = client.post("/engine/v1/resolve", json=body).json()
    replayed = client.post("/engine/v1/replay", json=body).json()
    assert resolved == replayed


def test_resolve_rejects_a_malformed_prize_table() -> None:
    body = _request_body()
    body["prize_table"] = LAUNCH_TABLE[:-1]  # missing TIER_LOSS
    response = client.post("/engine/v1/resolve", json=body)
    assert response.status_code == 422


def test_resolve_rejects_fewer_than_5_selected_positions() -> None:
    body = _request_body()
    body["player_input"]["selected_positions"] = [0, 1, 2]
    response = client.post("/engine/v1/resolve", json=body)
    assert response.status_code == 422


def test_resolve_rejects_a_non_hex_seed() -> None:
    body = _request_body()
    body["seed"] = "not-hex"
    response = client.post("/engine/v1/resolve", json=body)
    assert response.status_code == 422
