# syntax=docker/dockerfile:1
#
# Python FastAPI microservice (PyMuPDF page ops / bake, OCR, Word export, AI glue).
# 3.12 is used for the widest wheel compatibility (numpy/opencv via pdf2docx) — see plan §F.

FROM python:3.12-slim

WORKDIR /app

# pdf2docx pulls in opencv-python-headless, which needs a couple of shared libraries.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libglib2.0-0 libgl1 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PDF_TESSDATA_PREFIX=/app/tessdata \
    PDF_OCR_CACHE_DIR=/app/.ocr_cache

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app

# Created so the OCR cache / tessdata mounts have a target even when empty.
RUN mkdir -p /app/tessdata /app/.ocr_cache

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
