"""Tests for ``POST /pdf/bake`` — flattening overlay edits onto a copy of a PDF.

Baking must place edits at the right spot (overlays are in PDF user space, bottom-left
origin) while leaving the rest of the page untouched (the Golden Rule). Where practical we
render the baked page and sample pixels to prove both properties.
"""

from __future__ import annotations

import base64
import json

import fitz

_W, _H = 612, 792  # US Letter, in points

# A region we will cover with a whiteout and one we will leave alone, in fitz (top-left) coords.
_LEFT = fitz.Rect(100, 100, 200, 200)
_RIGHT = fitz.Rect(400, 100, 500, 200)


def _rects_pdf(black: list[fitz.Rect]) -> bytes:
    """A 1-page Letter PDF with the given rectangles filled black on a white background."""
    doc = fitz.open()
    page = doc.new_page(width=_W, height=_H)
    page.draw_rect(page.rect, color=(1, 1, 1), fill=(1, 1, 1))  # explicit white background
    for rect in black:
        page.draw_rect(rect, color=(0, 0, 0), fill=(0, 0, 0))
    data = doc.tobytes()
    doc.close()

    return data


def _overlay(type_: str, page_number: int = 1, **payload) -> dict:
    return {"type": type_, "page_number": page_number, "payload": payload}


def _fitz_to_pdf_rect(rect: fitz.Rect) -> dict:
    """Convert a fitz (top-left) rect into a bottom-left PDF-space overlay payload rect."""
    return {
        "x": rect.x0,
        "y": _H - rect.y1,
        "width": rect.width,
        "height": rect.height,
    }


def _bake(client, secret, pdf: bytes, overlays: list[dict]):
    return client.post(
        "/pdf/bake",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", pdf, "application/pdf")},
        data={"overlays": json.dumps(overlays)},
    )


def _pixel(pdf: bytes, x: int, y: int) -> tuple[int, int, int]:
    """Render page 1 at 72 dpi (1pt = 1px) and read one RGB pixel."""
    doc = fitz.open(stream=pdf, filetype="pdf")
    pix = doc[0].get_pixmap(dpi=72)
    rgb = pix.pixel(x, y)
    doc.close()

    return rgb


def _png_b64() -> str:
    """A tiny solid-red PNG, base64-encoded (for the image overlay)."""
    pix = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, 10, 10))
    pix.clear_with(0)  # value irrelevant; we only need valid PNG bytes
    return base64.b64encode(pix.tobytes("png")).decode("ascii")


def test_bake_requires_secret(client):
    response = client.post(
        "/pdf/bake",
        files={"file": ("doc.pdf", _rects_pdf([]), "application/pdf")},
        data={"overlays": "[]"},
    )
    assert response.status_code == 401


def test_bake_with_no_overlays_preserves_the_document(client, secret, native_pdf):
    response = _bake(client, secret, native_pdf, [])
    assert response.status_code == 200

    body = response.json()
    assert body["page_count"] == 2
    baked = base64.b64decode(body["content_base64"])
    # The text content survives an empty bake.
    doc = fitz.open(stream=baked, filetype="pdf")
    assert "Hello world" in doc[0].get_text()
    doc.close()


def test_whiteout_covers_only_its_region(client, secret):
    pdf = _rects_pdf([_LEFT, _RIGHT])
    # Sanity: both rectangles start black.
    assert _pixel(pdf, 150, 150) == (0, 0, 0)
    assert _pixel(pdf, 450, 150) == (0, 0, 0)

    overlay = _overlay("whiteout", **_fitz_to_pdf_rect(_LEFT))
    response = _bake(client, secret, pdf, [overlay])
    assert response.status_code == 200

    baked = base64.b64decode(response.json()["content_base64"])
    # The covered rectangle is now white; the untouched one stays pixel-identical (black).
    assert _pixel(baked, 150, 150) == (255, 255, 255)
    assert _pixel(baked, 450, 150) == (0, 0, 0)


def test_whiteout_on_a_rotated_page_lands_in_user_space(client, secret):
    # A /Rotate 90 page with two black squares placed in (unrotated) user space.
    doc = fitz.open()
    page = doc.new_page(width=_W, height=_H)
    page.draw_rect(page.rect, color=(1, 1, 1), fill=(1, 1, 1))
    page.draw_rect(_LEFT, color=(0, 0, 0), fill=(0, 0, 0))
    page.draw_rect(_RIGHT, color=(0, 0, 0), fill=(0, 0, 0))
    page.set_rotation(90)
    pdf = doc.tobytes()
    doc.close()

    # The editor sends geometry in unrotated user space (PDF.js convertToPdfPoint), so the
    # whiteout payload for _LEFT is the same as on an unrotated page.
    response = _bake(client, secret, pdf, [_overlay("whiteout", **_fitz_to_pdf_rect(_LEFT))])
    assert response.status_code == 200

    baked = base64.b64decode(response.json()["content_base64"])
    doc = fitz.open(stream=baked, filetype="pdf")
    page = doc[0]
    # Rotation is preserved, so the baked page still displays as the editor showed it.
    assert page.rotation == 90
    # De-rotate to inspect user space: the whiteout covered _LEFT; _RIGHT is untouched.
    page.set_rotation(0)
    pix = page.get_pixmap(dpi=72)
    assert pix.pixel(150, 150) == (255, 255, 255)
    assert pix.pixel(450, 150) == (0, 0, 0)
    doc.close()


def test_text_overlay_adds_extractable_text(client, secret):
    pdf = _rects_pdf([])
    overlay = _overlay(
        "text",
        x=72,
        y=700,
        width=300,
        height=40,
        text="Corrected value 42",
        font_size=18,
    )
    response = _bake(client, secret, pdf, [overlay])
    assert response.status_code == 200

    baked = base64.b64decode(response.json()["content_base64"])
    doc = fitz.open(stream=baked, filetype="pdf")
    assert "Corrected value 42" in doc[0].get_text()
    doc.close()


def test_highlight_tints_its_region_without_hiding_content(client, secret):
    pdf = _rects_pdf([])  # white page
    overlay = _overlay(
        "highlight",
        **_fitz_to_pdf_rect(_LEFT),
        color="#ffff00",
        opacity=0.5,
    )
    response = _bake(client, secret, pdf, [overlay])
    assert response.status_code == 200

    baked = base64.b64decode(response.json()["content_base64"])
    r, g, b = _pixel(baked, 150, 150)
    # Yellow tint over white: red & green stay high, blue drops.
    assert r > 200 and g > 200 and b < 200
    # Outside the highlight the page is still white.
    assert _pixel(baked, 450, 150) == (255, 255, 255)


def test_all_visual_overlay_types_bake(client, secret):
    pdf = _rects_pdf([])
    overlays = [
        _overlay("underline", x=72, y=700, width=200, height=20, color="#000000"),
        _overlay("strike", x=72, y=600, width=200, height=20, color="#ff0000"),
        _overlay("shape", kind="rect", x=300, y=600, width=80, height=80, stroke="#0000ff"),
        _overlay("shape", kind="ellipse", x=300, y=400, width=80, height=80, fill="#00ff00"),
        _overlay("shape", kind="line", x=72, y=300, width=200, height=50, stroke="#000000"),
        _overlay("freehand", points=[[72, 200], [120, 240], [180, 200]], stroke="#123456"),
        _overlay("image", x=400, y=200, width=60, height=60, data=_png_b64()),
    ]
    response = _bake(client, secret, pdf, overlays)
    assert response.status_code == 200
    assert response.json()["page_count"] == 1


def test_unknown_types_are_skipped(client, secret):
    pdf = _rects_pdf([])
    overlay = _overlay("mystery", x=72, y=700, width=100, height=40)
    response = _bake(client, secret, pdf, [overlay])
    # An unknown overlay type bakes to a no-op rather than failing the whole request.
    assert response.status_code == 200


def test_signature_overlay_bakes_as_an_image(client, secret):
    pdf = _rects_pdf([])  # white page, no images
    overlay = _overlay("signature", x=400, y=200, width=80, height=40, data=_png_b64())
    response = _bake(client, secret, pdf, [overlay])
    assert response.status_code == 200

    baked = base64.b64decode(response.json()["content_base64"])
    doc = fitz.open(stream=baked, filetype="pdf")
    # The signature is rasterized onto the page like any image overlay.
    assert len(doc[0].get_images()) == 1
    doc.close()


def test_form_field_text_bakes_its_value(client, secret):
    pdf = _rects_pdf([])
    overlay = _overlay(
        "form_field",
        x=72,
        y=700,
        width=200,
        height=20,
        field_type="text",
        value="Jane Q. Public",
        font_size=12,
    )
    response = _bake(client, secret, pdf, [overlay])
    assert response.status_code == 200

    baked = base64.b64decode(response.json()["content_base64"])
    doc = fitz.open(stream=baked, filetype="pdf")
    assert "Jane Q. Public" in doc[0].get_text()
    doc.close()


def test_form_field_checkbox_draws_a_mark_only_when_checked(client, secret):
    pdf = _rects_pdf([])
    overlays = [
        _overlay(
            "form_field", x=100, y=700, width=15, height=15, field_type="checkbox", value=True
        ),
        _overlay(
            "form_field", x=100, y=650, width=15, height=15, field_type="checkbox", value=False
        ),
    ]
    response = _bake(client, secret, pdf, overlays)
    assert response.status_code == 200

    baked = base64.b64decode(response.json()["content_base64"])
    doc = fitz.open(stream=baked, filetype="pdf")
    # A checked box flattens to a single "X"; the unchecked one draws nothing.
    assert doc[0].get_text().count("X") == 1
    doc.close()


def test_out_of_range_page_is_rejected(client, secret):
    pdf = _rects_pdf([])  # single page
    overlay = _overlay("whiteout", page_number=5, x=10, y=10, width=10, height=10)
    response = _bake(client, secret, pdf, [overlay])
    assert response.status_code == 422


def test_invalid_overlays_json_is_rejected(client, secret):
    response = client.post(
        "/pdf/bake",
        headers={"X-Pdf-Secret": secret},
        files={"file": ("doc.pdf", _rects_pdf([]), "application/pdf")},
        data={"overlays": "not-json"},
    )
    assert response.status_code == 422


def test_invalid_overlay_payload_is_rejected(client, secret):
    pdf = _rects_pdf([])
    # A text overlay missing required rect fields.
    overlay = {"type": "text", "page_number": 1, "payload": {"text": "hi"}}
    response = _bake(client, secret, pdf, [overlay])
    assert response.status_code == 422
