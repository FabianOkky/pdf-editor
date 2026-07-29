"""Tests for the AcroForm endpoints (``/pdf/form-fields`` and ``/pdf/form-fields/fill``).

Detection must report each field in PDF user space (bottom-left origin) so the editor can
place an input over it; filling must set the right values, skip read-only fields, and — when
asked — flatten the widgets into static, extractable content. Filling never mutates the input
bytes; it works on an in-memory copy (the Golden Rule).
"""

from __future__ import annotations

import base64
import json

import fitz

_H = 792  # US Letter height, in points (matches the form_pdf fixture).


def _detect(client, secret, pdf: bytes):
    return client.post(
        "/pdf/form-fields",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", pdf, "application/pdf")},
    )


def _fill(client, secret, pdf: bytes, values: dict, flatten: bool = False):
    return client.post(
        "/pdf/form-fields/fill",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", pdf, "application/pdf")},
        data={"values": json.dumps(values), "flatten": str(flatten).lower()},
    )


def _by_name(fields: list[dict]) -> dict[str, dict]:
    return {field["name"]: field for field in fields}


def test_form_fields_requires_secret(client, form_pdf):
    response = client.post(
        "/pdf/form-fields",
        files={"file": ("doc.pdf", form_pdf, "application/pdf")},
    )
    assert response.status_code == 401


def test_detects_all_field_types(client, secret, form_pdf):
    response = _detect(client, secret, form_pdf)
    assert response.status_code == 200

    body = response.json()
    assert body["is_form"] is True

    fields = _by_name(body["fields"])
    assert set(fields) == {"full_name", "agree", "color", "ref_no"}
    assert fields["full_name"]["type"] == "text"
    assert fields["agree"]["type"] == "checkbox"
    assert fields["color"]["type"] == "combobox"
    assert fields["color"]["options"] == ["Red", "Green", "Blue"]
    assert fields["color"]["value"] == "Red"
    # Every field is on page 1.
    assert {field["page_number"] for field in body["fields"]} == {1}


def test_detected_rect_is_in_bottom_left_user_space(client, secret, form_pdf):
    # full_name sits at fitz (top-left) y 100..120; in bottom-left space that is 792-120 = 672.
    field = _by_name(_detect(client, secret, form_pdf).json()["fields"])["full_name"]
    assert field["x"] == 100
    assert field["y"] == _H - 120
    assert field["width"] == 200
    assert field["height"] == 20


def test_detects_readonly_flag(client, secret, form_pdf):
    fields = _by_name(_detect(client, secret, form_pdf).json()["fields"])
    assert fields["ref_no"]["readonly"] is True
    assert fields["full_name"]["readonly"] is False


def test_detect_on_a_non_form_pdf_is_empty(client, secret, native_pdf):
    body = _detect(client, secret, native_pdf).json()
    assert body["is_form"] is False
    assert body["fields"] == []


def test_fill_sets_values_without_flattening(client, secret, form_pdf):
    response = _fill(
        client,
        secret,
        form_pdf,
        {"full_name": "Jane Q. Public", "agree": True, "color": "Blue"},
    )
    assert response.status_code == 200

    filled = base64.b64decode(response.json()["content_base64"])
    doc = fitz.open(stream=filled, filetype="pdf")
    # Still an interactive form; the widget values were updated.
    assert doc.is_form_pdf
    values = {w.field_name: w.field_value for w in doc[0].widgets()}
    doc.close()

    assert values["full_name"] == "Jane Q. Public"
    assert values["agree"] == "Yes"  # checkbox "on" state
    assert values["color"] == "Blue"


def test_fill_skips_readonly_fields(client, secret, form_pdf):
    response = _fill(client, secret, form_pdf, {"ref_no": "HACKED"})
    filled = base64.b64decode(response.json()["content_base64"])

    doc = fitz.open(stream=filled, filetype="pdf")
    values = {w.field_name: w.field_value for w in doc[0].widgets()}
    doc.close()

    assert values["ref_no"] == "LOCKED"


def test_fill_and_flatten_removes_widgets_and_keeps_text(client, secret, form_pdf):
    response = _fill(
        client,
        secret,
        form_pdf,
        {"full_name": "Jane Q. Public", "agree": True, "color": "Green"},
        flatten=True,
    )
    assert response.status_code == 200

    flat = base64.b64decode(response.json()["content_base64"])
    doc = fitz.open(stream=flat, filetype="pdf")
    # Flattening bakes appearances into the page and drops the interactive widgets.
    assert doc.is_form_pdf is False
    assert len(list(doc[0].widgets())) == 0
    text = doc[0].get_text()
    doc.close()

    assert "Jane Q. Public" in text
    assert "Green" in text


def test_fill_rejects_non_json_values(client, secret, form_pdf):
    response = client.post(
        "/pdf/form-fields/fill",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", form_pdf, "application/pdf")},
        data={"values": "not-json"},
    )
    assert response.status_code == 422


def test_fill_rejects_non_object_values(client, secret, form_pdf):
    response = client.post(
        "/pdf/form-fields/fill",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", form_pdf, "application/pdf")},
        data={"values": "[1, 2, 3]"},
    )
    assert response.status_code == 422
