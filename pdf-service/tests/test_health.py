"""Tests for the health endpoint and shared-secret auth."""

from __future__ import annotations

from fastapi.testclient import TestClient


def test_health_ok_with_valid_secret(client: TestClient, secret: str) -> None:
    response = client.get("/health", headers={"X-Pdf-Secret": secret})

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "ok"
    assert body["service"]
    assert body["version"]


def test_health_rejects_missing_secret(client: TestClient) -> None:
    response = client.get("/health")

    assert response.status_code == 401


def test_health_rejects_wrong_secret(client: TestClient) -> None:
    response = client.get("/health", headers={"X-Pdf-Secret": "wrong-secret"})

    assert response.status_code == 401
