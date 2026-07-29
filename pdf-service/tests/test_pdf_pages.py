"""Tests for ``POST /pdf/pages`` (organize / split / merge)."""

from __future__ import annotations

import base64
import json

import fitz
from fastapi.testclient import TestClient


def _numbered_pdf(count: int, *, rotation: int = 0) -> bytes:
    """Build a ``count``-page PDF where page *k* contains the text ``PAGE-k``."""
    doc = fitz.open()
    for number in range(1, count + 1):
        page = doc.new_page(width=300, height=400)
        page.insert_text((72, 72), f"PAGE-{number}")
        if rotation:
            page.set_rotation(rotation)
    data = doc.tobytes()
    doc.close()

    return data


def _open_output(output: dict) -> fitz.Document:
    """Open a single ``{page_count, content_base64}`` output as a PyMuPDF document."""
    return fitz.open(stream=base64.b64decode(output["content_base64"]), filetype="pdf")


def _page_labels(doc: fitz.Document) -> list[str]:
    """Return the first text token on each page (e.g. ``["PAGE-3", "PAGE-1"]``)."""
    return [page.get_text("text").strip() for page in doc]


def _post_pages(client: TestClient, secret: str, files: list[bytes], spec: dict):
    return client.post(
        "/pdf/pages",
        headers={"X-Pdf-Secret": secret},
        files=[("files", (f"in-{i}.pdf", data, "application/pdf")) for i, data in enumerate(files)],
        data={"spec": json.dumps(spec)},
    )


def test_pages_requires_secret(client: TestClient) -> None:
    response = client.post(
        "/pdf/pages",
        files=[("files", ("in.pdf", _numbered_pdf(1), "application/pdf"))],
        data={"spec": json.dumps({"op": "organize", "pages": [{"source": 1}]})},
    )

    assert response.status_code == 401


def test_organize_reorders_pages(client: TestClient, secret: str) -> None:
    spec = {"op": "organize", "pages": [{"source": 3}, {"source": 1}, {"source": 2}]}

    response = _post_pages(client, secret, [_numbered_pdf(3)], spec)

    assert response.status_code == 200
    outputs = response.json()["outputs"]
    assert len(outputs) == 1
    assert outputs[0]["page_count"] == 3

    with _open_output(outputs[0]) as doc:
        assert _page_labels(doc) == ["PAGE-3", "PAGE-1", "PAGE-2"]


def test_organize_deletes_pages_by_omission(client: TestClient, secret: str) -> None:
    spec = {"op": "organize", "pages": [{"source": 1}, {"source": 3}]}

    outputs = _post_pages(client, secret, [_numbered_pdf(3)], spec).json()["outputs"]

    assert outputs[0]["page_count"] == 2
    with _open_output(outputs[0]) as doc:
        assert _page_labels(doc) == ["PAGE-1", "PAGE-3"]


def test_organize_rotates_pages(client: TestClient, secret: str) -> None:
    spec = {"op": "organize", "pages": [{"source": 1, "rotate": 90}]}

    outputs = _post_pages(client, secret, [_numbered_pdf(1)], spec).json()["outputs"]

    with _open_output(outputs[0]) as doc:
        assert doc[0].rotation == 90


def test_organize_rotation_is_a_delta_on_existing_rotation(client: TestClient, secret: str) -> None:
    # The source page already sits at 90°; adding 90° more should land on 180°.
    spec = {"op": "organize", "pages": [{"source": 1, "rotate": 90}]}

    outputs = _post_pages(client, secret, [_numbered_pdf(1, rotation=90)], spec).json()["outputs"]

    with _open_output(outputs[0]) as doc:
        assert doc[0].rotation == 180


def test_organize_rejects_out_of_range_page(client: TestClient, secret: str) -> None:
    spec = {"op": "organize", "pages": [{"source": 99}]}

    response = _post_pages(client, secret, [_numbered_pdf(2)], spec)

    assert response.status_code == 422


def test_organize_rejects_empty_page_list(client: TestClient, secret: str) -> None:
    response = _post_pages(client, secret, [_numbered_pdf(2)], {"op": "organize", "pages": []})

    assert response.status_code == 422


def test_split_by_ranges(client: TestClient, secret: str) -> None:
    spec = {"op": "split", "ranges": [[1, 2], [3, 5]]}

    outputs = _post_pages(client, secret, [_numbered_pdf(5)], spec).json()["outputs"]

    assert [output["page_count"] for output in outputs] == [2, 3]
    with _open_output(outputs[0]) as first, _open_output(outputs[1]) as second:
        assert _page_labels(first) == ["PAGE-1", "PAGE-2"]
        assert _page_labels(second) == ["PAGE-3", "PAGE-4", "PAGE-5"]


def test_split_every_n_pages(client: TestClient, secret: str) -> None:
    spec = {"op": "split", "every": 2}

    outputs = _post_pages(client, secret, [_numbered_pdf(5)], spec).json()["outputs"]

    # 5 pages in groups of 2 => [2, 2, 1].
    assert [output["page_count"] for output in outputs] == [2, 2, 1]


def test_split_rejects_out_of_range(client: TestClient, secret: str) -> None:
    spec = {"op": "split", "ranges": [[1, 9]]}

    response = _post_pages(client, secret, [_numbered_pdf(3)], spec)

    assert response.status_code == 422


def test_merge_combines_files_in_order(client: TestClient, secret: str) -> None:
    first = _numbered_pdf(2)
    second = _numbered_pdf(1)

    outputs = _post_pages(client, secret, [first, second], {"op": "merge"}).json()["outputs"]

    assert len(outputs) == 1
    assert outputs[0]["page_count"] == 3
    with _open_output(outputs[0]) as doc:
        # First file's pages come first, then the second file's page.
        assert _page_labels(doc) == ["PAGE-1", "PAGE-2", "PAGE-1"]


def test_merge_requires_at_least_two_files(client: TestClient, secret: str) -> None:
    response = _post_pages(client, secret, [_numbered_pdf(2)], {"op": "merge"})

    assert response.status_code == 422


def test_pages_rejects_corrupt_pdf(client: TestClient, secret: str) -> None:
    spec = {"op": "organize", "pages": [{"source": 1}]}

    response = _post_pages(client, secret, [b"not a pdf"], spec)

    assert response.status_code == 422


def test_pages_rejects_invalid_spec(client: TestClient, secret: str) -> None:
    response = client.post(
        "/pdf/pages",
        headers={"X-Pdf-Secret": secret},
        files=[("files", ("in.pdf", _numbered_pdf(1), "application/pdf"))],
        data={"spec": "{not valid json"},
    )

    assert response.status_code == 422
