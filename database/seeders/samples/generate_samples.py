"""Generate the demo sample PDFs (+ cover thumbnails + a manifest) used by the seeder.

Run from the repo root with the pdf-service venv:

    pdf-service/.venv/Scripts/python.exe database/seeders/samples/generate_samples.py

The output (PDFs, PNG thumbnails, manifest.json) is committed so `php artisan db:seed`
works offline without the Python service running. Re-run only to refresh the samples.
"""

from __future__ import annotations

import json
from pathlib import Path

import fitz  # PyMuPDF

OUT = Path(__file__).resolve().parent
LETTER = fitz.paper_rect("letter")  # 612 x 792 points

HEADING = (0.11, 0.16, 0.36)
BODY = (0.15, 0.15, 0.18)
MUTED = (0.4, 0.4, 0.45)


def _page(doc: fitz.Document) -> fitz.Page:
    return doc.new_page(width=LETTER.width, height=LETTER.height)


def _text(
    page: fitz.Page, x: float, y: float, text: str, size: int = 11, color=BODY, font: str = "helv"
) -> None:
    page.insert_text((x, y), text, fontsize=size, color=color, fontname=font)


def build_welcome() -> fitz.Document:
    doc = fitz.open()
    page = _page(doc)
    _text(page, 72, 96, "Welcome to PDF Studio", size=26, color=HEADING, font="hebo")
    _text(
        page,
        72,
        128,
        "Edit your PDFs live in the browser — non-destructively.",
        size=12,
        color=MUTED,
    )
    lines = [
        "This is a sample document so you can try the editor right away.",
        "",
        "Things to try:",
        "  -  Add text, highlights and shapes on the Edit screen (overlay layer).",
        "  -  Reorder, rotate, split or merge pages from Organize.",
        "  -  Ask the AI assistant to summarize or translate this page.",
        "  -  Export the document to Word and compare the layout.",
        "",
        "Your original upload is never modified. Every edit is stored as a structured",
        "overlay and baked onto a copy on demand, saved as a new version.",
    ]
    y = 176
    for line in lines:
        _text(page, 72, y, line, size=12)
        y += 22
    return doc


def build_report() -> fitz.Document:
    doc = fitz.open()
    sections = [
        (
            "Quarterly Report",
            "Q2 — Overview",
            [
                "Revenue grew 18% quarter over quarter, driven by new sign-ups.",
                "Churn held steady at 2.1%. Support volume decreased by 9%.",
                "This three-page report is handy for trying page operations.",
            ],
        ),
        (
            "Quarterly Report",
            "Q2 — Metrics",
            [
                "Monthly active users: 12,480 (+14%).",
                "Average session length: 7m 42s.",
                "Documents processed: 38,902.",
            ],
        ),
        (
            "Quarterly Report",
            "Q2 — Outlook",
            [
                "Next quarter focuses on collaboration and export fidelity.",
                "Hiring two engineers and one designer.",
                "Try reordering or splitting these pages, then save a version.",
            ],
        ),
    ]
    for index, (title, subtitle, lines) in enumerate(sections, start=1):
        page = _page(doc)
        _text(page, 72, 96, title, size=24, color=HEADING, font="hebo")
        _text(page, 72, 124, subtitle, size=13, color=MUTED)
        y = 168
        for line in lines:
            _text(page, 72, y, line, size=12)
            y += 24
        _text(page, 72, 760, f"Page {index} of {len(sections)}", size=9, color=MUTED)
    return doc


def build_invoice() -> fitz.Document:
    doc = fitz.open()
    page = _page(doc)
    _text(page, 72, 90, "INVOICE", size=28, color=HEADING, font="hebo")
    _text(page, 72, 120, "PDF Studio LLC", size=11, color=MUTED)
    _text(page, 430, 96, "Invoice #  INV-2026-014", size=11)
    _text(page, 430, 114, "Date       2026-06-22", size=11)
    _text(page, 430, 132, "Due        2026-07-22", size=11)

    page.draw_line((72, 170), (540, 170), color=MUTED, width=0.8)
    _text(page, 72, 188, "Description", size=11, color=MUTED)
    _text(page, 400, 188, "Qty", size=11, color=MUTED)
    _text(page, 470, 188, "Amount", size=11, color=MUTED)
    page.draw_line((72, 198), (540, 198), color=MUTED, width=0.8)

    rows = [
        ("Document processing — Pro plan", "1", "$49.00"),
        ("OCR pages (overage)", "120", "$12.00"),
        ("Priority support", "1", "$15.00"),
    ]
    y = 222
    for desc, qty, amount in rows:
        _text(page, 72, y, desc, size=11)
        _text(page, 400, y, qty, size=11)
        _text(page, 470, y, amount, size=11)
        y += 24

    page.draw_line((72, y + 6), (540, y + 6), color=MUTED, width=0.8)
    _text(page, 400, y + 28, "Total", size=12, color=HEADING, font="hebo")
    _text(page, 470, y + 28, "$76.00", size=12, color=HEADING, font="hebo")
    _text(
        page,
        72,
        760,
        "Thank you for your business. This sample is great for Word export.",
        size=9,
        color=MUTED,
    )
    return doc


SAMPLES = [
    ("sample-welcome.pdf", "Welcome to PDF Studio", build_welcome),
    ("sample-quarterly-report.pdf", "Quarterly Report", build_report),
    ("sample-invoice.pdf", "Service Invoice", build_invoice),
]


def main() -> None:
    manifest = []
    for filename, title, builder in SAMPLES:
        doc = builder()
        pdf_path = OUT / filename
        doc.save(str(pdf_path), deflate=True, garbage=4)

        pages = [{"width": round(p.rect.width, 2), "height": round(p.rect.height, 2)} for p in doc]

        # Cover thumbnail (page 1 @ ~96 dpi) — mirrors the upload flow's cover thumbnail.
        thumb_name = filename.replace(".pdf", ".png")
        pixmap = doc[0].get_pixmap(matrix=fitz.Matrix(96 / 72, 96 / 72))
        pixmap.save(str(OUT / thumb_name))

        manifest.append(
            {
                "file": filename,
                "thumbnail": thumb_name,
                "title": title,
                "page_count": doc.page_count,
                "source_type": "native",
                "pages": pages,
                "size_bytes": pdf_path.stat().st_size,
            }
        )
        doc.close()
        print(f"wrote {filename} ({manifest[-1]['page_count']} pages) + {thumb_name}")

    (OUT / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"wrote manifest.json ({len(manifest)} samples)")


if __name__ == "__main__":
    main()
