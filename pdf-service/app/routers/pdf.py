"""PDF routes: inspection (``/pdf/info``, ``/pdf/thumbnails``) and page operations
(``/pdf/pages``).

All accept the PDF(s) as multipart ``file``/``files`` uploads and require the shared
secret. See ARCHITECTURE.md §4 for the Laravel ↔ Python contract.
"""

from __future__ import annotations

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile

from app.core.auth import verify_secret
from app.schemas.export import DocxExportResponse, OcrResponse
from app.schemas.extract import ExtractTextResponse
from app.schemas.forms import FormFieldsResponse, FormFillResponse
from app.schemas.overlays import BakeResponse
from app.schemas.pages import PagesResponse
from app.schemas.pdf import PdfInfoResponse, ThumbnailsResponse
from app.services.pdf_bake import bake_overlays, parse_overlays
from app.services.pdf_export import export_docx
from app.services.pdf_extract import extract_text
from app.services.pdf_forms import fill_form_fields, list_form_fields, parse_fill_values
from app.services.pdf_info import analyze_pdf
from app.services.pdf_ocr import ocr_pdf
from app.services.pdf_pages import apply_page_operation, parse_pages_spec
from app.services.pdf_thumbnails import render_thumbnails

router = APIRouter(prefix="/pdf", tags=["pdf"], dependencies=[Depends(verify_secret)])


@router.post("/info", response_model=PdfInfoResponse)
async def pdf_info(file: UploadFile = File(...)) -> PdfInfoResponse:
    """Return page count, per-page sizes, and a native/scanned/mixed classification."""
    data = await file.read()
    try:
        info = analyze_pdf(data)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return PdfInfoResponse(**info)


@router.post("/thumbnails", response_model=ThumbnailsResponse)
async def pdf_thumbnails(
    file: UploadFile = File(...),
    pages: str | None = Form(default=None),
    dpi: int = Form(default=96),
) -> ThumbnailsResponse:
    """Render the given (or all) pages to base64 PNG thumbnails.

    ``pages`` is a comma-separated list of 1-based page numbers (e.g. ``"1,2,5"``);
    omit it to render every page (capped server-side).
    """
    data = await file.read()
    try:
        thumbnails = render_thumbnails(data, _parse_pages(pages), dpi)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return ThumbnailsResponse(thumbnails=thumbnails)


@router.post("/pages", response_model=PagesResponse)
async def pdf_pages(
    files: list[UploadFile] = File(...),
    spec: str = Form(...),
) -> PagesResponse:
    """Run a page-level operation (organize / split / merge) and return the new PDF(s).

    ``spec`` is a JSON object discriminated on ``op`` (see ``schemas/pages.py``). Provide a
    single ``files`` part for organize/split, or two-plus (in order) for merge. Pages are
    copied losslessly — never re-rendered — so fidelity is preserved (the Golden Rule).
    """
    try:
        parsed = parse_pages_spec(spec)
        payloads = [await upload.read() for upload in files]
        outputs = apply_page_operation(parsed, payloads)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return PagesResponse(outputs=outputs)


@router.post("/bake", response_model=BakeResponse)
async def pdf_bake(
    file: UploadFile = File(...),
    overlays: str = Form(...),
) -> BakeResponse:
    """Flatten overlay edits onto a copy of the PDF and return the new document.

    ``overlays`` is a JSON array of ``{type, page_number, z_index, order, payload}`` objects
    (see ``schemas/overlays.py``); geometry is in PDF user space (points, bottom-left origin).
    The original bytes are never modified — overlays are drawn on an in-memory copy (the
    Golden Rule).
    """
    data = await file.read()
    try:
        parsed = parse_overlays(overlays)
        result = bake_overlays(data, parsed)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return BakeResponse(**result)


@router.post("/form-fields", response_model=FormFieldsResponse)
async def pdf_form_fields(file: UploadFile = File(...)) -> FormFieldsResponse:
    """List the PDF's interactive AcroForm fields (name, type, value, page, rect, options).

    Field rectangles come back in PDF user space (points, bottom-left origin) so the editor
    can position an input over each field. A PDF without an AcroForm yields an empty list.
    """
    data = await file.read()
    try:
        result = list_form_fields(data)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return FormFieldsResponse(**result)


@router.post("/form-fields/fill", response_model=FormFillResponse)
async def pdf_form_fields_fill(
    file: UploadFile = File(...),
    values: str = Form(...),
    flatten: bool = Form(default=False),
) -> FormFillResponse:
    """Fill AcroForm values onto a copy of the PDF, optionally flattening the widgets.

    ``values`` is a JSON object mapping field name → value (booleans for checkboxes). When
    ``flatten`` is true the filled appearances are baked into static content and the widgets
    removed. The original bytes are never modified (the Golden Rule).
    """
    data = await file.read()
    try:
        parsed = parse_fill_values(values)
        result = fill_form_fields(data, parsed, flatten)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return FormFillResponse(**result)


@router.post("/ocr", response_model=OcrResponse)
async def pdf_ocr(
    file: UploadFile = File(...),
    language: str | None = Form(default=None),
) -> OcrResponse:
    """OCR a (scanned) PDF into a searchable PDF and return it plus the recognized text.

    ``language`` is a Tesseract language code (default ``eng``). Results are cached by content
    hash so repeated calls are cheap. Requires the language's tessdata (see the README);
    otherwise this returns 422. The original bytes are never modified (the Golden Rule).
    """
    data = await file.read()
    try:
        result = ocr_pdf(data, language=language)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return OcrResponse(**result)


@router.post("/extract-text", response_model=ExtractTextResponse)
async def pdf_extract_text(
    file: UploadFile = File(...),
    language: str | None = Form(default=None),
) -> ExtractTextResponse:
    """Extract the document's text per page (and concatenated) for the AI/RAG layer.

    Native pages are read straight from the text layer; scanned/mixed documents are OCR'd first
    (``language`` is a Tesseract code, default ``eng``) so their words are recoverable. When the
    OCR language data is unavailable the response degrades to the available native text rather
    than failing. The original bytes are never modified (the Golden Rule).
    """
    data = await file.read()
    try:
        result = extract_text(data, language=language)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return ExtractTextResponse(**result)


@router.post("/export/docx", response_model=DocxExportResponse)
async def pdf_export_docx(
    file: UploadFile = File(...),
    language: str | None = Form(default=None),
) -> DocxExportResponse:
    """Convert a PDF to an editable ``.docx`` with the smart native-vs-scanned pipeline.

    Native PDFs go straight through pdf2docx; scanned/mixed PDFs are OCR'd first so the text is
    recoverable (the step naive converters skip). The response reports ``source_type`` and
    whether OCR ran so the app can set honest, best-effort expectations. Conversion is
    layout-aware but not pixel-perfect. A scanned source with no OCR data available yields 422.
    """
    data = await file.read()
    try:
        result = export_docx(data, language=language)
    except ValueError as exc:
        raise HTTPException(422, detail=str(exc)) from exc

    return DocxExportResponse(**result)


def _parse_pages(pages: str | None) -> list[int] | None:
    """Parse a ``"1,2,5"`` string into ``[1, 2, 5]``; return ``None`` when empty."""
    if not pages:
        return None

    parsed: list[int] = []
    for chunk in pages.split(","):
        chunk = chunk.strip()
        if not chunk:
            continue
        try:
            parsed.append(int(chunk))
        except ValueError as exc:
            raise HTTPException(
                422,
                detail=f"Invalid page number: {chunk!r}",
            ) from exc

    return parsed or None
