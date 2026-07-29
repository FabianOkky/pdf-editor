"""Tests for ``POST /pdf/info`` and the source-type heuristic."""

from __future__ import annotations

from fastapi.testclient import TestClient


def _post_info(client: TestClient, secret: str, data: bytes):
    return client.post(
        "/pdf/info",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", data, "application/pdf")},
    )


def test_info_requires_secret(client: TestClient, native_pdf: bytes) -> None:
    response = client.post(
        "/pdf/info",
        files={"file": ("doc.pdf", native_pdf, "application/pdf")},
    )

    assert response.status_code == 401


def test_info_reports_page_count_and_sizes(
    client: TestClient, secret: str, native_pdf: bytes
) -> None:
    response = _post_info(client, secret, native_pdf)

    assert response.status_code == 200
    body = response.json()
    assert body["page_count"] == 2
    assert len(body["pages"]) == 2
    # US Letter in points.
    assert body["pages"][0]["width"] == 612.0
    assert body["pages"][0]["height"] == 792.0


def test_info_detects_native(client: TestClient, secret: str, native_pdf: bytes) -> None:
    body = _post_info(client, secret, native_pdf).json()

    assert body["source_type"] == "native"


def test_info_detects_scanned(client: TestClient, secret: str, scanned_pdf: bytes) -> None:
    body = _post_info(client, secret, scanned_pdf).json()

    assert body["source_type"] == "scanned"


def test_info_detects_mixed(client: TestClient, secret: str, mixed_pdf: bytes) -> None:
    body = _post_info(client, secret, mixed_pdf).json()

    assert body["source_type"] == "mixed"


def test_info_rejects_corrupt_pdf(client: TestClient, secret: str) -> None:
    response = _post_info(client, secret, b"this is not a pdf")

    assert response.status_code == 422
