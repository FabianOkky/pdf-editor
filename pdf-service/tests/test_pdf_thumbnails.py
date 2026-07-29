"""Tests for ``POST /pdf/thumbnails``."""

from __future__ import annotations

import base64

from fastapi.testclient import TestClient


def _post_thumbnails(client: TestClient, secret: str, data: bytes, **form):
    return client.post(
        "/pdf/thumbnails",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", data, "application/pdf")},
        data=form,
    )


def test_thumbnails_requires_secret(client: TestClient, native_pdf: bytes) -> None:
    response = client.post(
        "/pdf/thumbnails",
        files={"file": ("doc.pdf", native_pdf, "application/pdf")},
    )

    assert response.status_code == 401


def test_thumbnails_renders_all_pages_by_default(
    client: TestClient, secret: str, native_pdf: bytes
) -> None:
    response = _post_thumbnails(client, secret, native_pdf)

    assert response.status_code == 200
    thumbnails = response.json()["thumbnails"]
    assert [thumbnail["page"] for thumbnail in thumbnails] == [1, 2]


def test_thumbnails_renders_requested_page_as_png(
    client: TestClient, secret: str, native_pdf: bytes
) -> None:
    response = _post_thumbnails(client, secret, native_pdf, pages="1")

    assert response.status_code == 200
    thumbnails = response.json()["thumbnails"]
    assert len(thumbnails) == 1

    thumbnail = thumbnails[0]
    assert thumbnail["page"] == 1
    assert thumbnail["format"] == "png"
    assert thumbnail["width"] > 0 and thumbnail["height"] > 0
    # The payload decodes to real PNG bytes (PNG magic number).
    assert base64.b64decode(thumbnail["image_base64"]).startswith(b"\x89PNG\r\n\x1a\n")


def test_thumbnails_dpi_changes_pixel_size(
    client: TestClient, secret: str, native_pdf: bytes
) -> None:
    low = _post_thumbnails(client, secret, native_pdf, pages="1", dpi=36).json()["thumbnails"][0]
    high = _post_thumbnails(client, secret, native_pdf, pages="1", dpi=144).json()["thumbnails"][0]

    assert high["width"] > low["width"]


def test_thumbnails_ignores_out_of_range_pages(
    client: TestClient, secret: str, native_pdf: bytes
) -> None:
    response = _post_thumbnails(client, secret, native_pdf, pages="1,99")

    thumbnails = response.json()["thumbnails"]
    assert [thumbnail["page"] for thumbnail in thumbnails] == [1]


def test_thumbnails_rejects_corrupt_pdf(client: TestClient, secret: str) -> None:
    response = _post_thumbnails(client, secret, b"not a pdf")

    assert response.status_code == 422
