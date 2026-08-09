/**
 * PDF.js-powered read-only viewer, exposed as an Alpine component factory (`pdfViewer`).
 *
 * Phase 1 scope: render pages to a canvas with page navigation, zoom, and a thumbnail rail.
 * It deliberately keeps a `viewport` reference and exposes `toPdfPoint` / `toScreenPoint`
 * so Phase 3 can layer an overlay editor on top without reworking the rendering core.
 */

import * as pdfjsLib from 'pdfjs-dist';

import { pdfToScreen, screenToPdf } from './coords';
import { ensurePdfWorker } from './worker';

const ZOOM_STEP = 0.25;
const MIN_SCALE = 0.25;
const MAX_SCALE = 4;
const THUMBNAIL_SCALE = 0.2;

/**
 * @param {{ url: string, pageCount?: number }} config
 */
export function pdfViewer({ url, pageCount = 0 }) {
    // PDF.js objects must stay OUT of Alpine's reactive state: Alpine wraps reactive
    // properties in a Proxy, and PDF.js relies on private class fields (`#…`) whose brand
    // checks throw "Cannot read private member …" when the receiver is a Proxy. Hold the
    // document and its render task in this non-reactive closure. `viewport` (a plain
    // PageViewport, no private fields) stays reactive for the coordinate helpers.
    const refs = { pdf: null, renderTask: null };

    return {
        url,
        pageCount,
        currentPage: 1,
        scale: 1,
        loading: true,
        error: false,

        /** @type {import('pdfjs-dist').PageViewport|null} */
        viewport: null,

        async init() {
            try {
                await ensurePdfWorker();
                refs.pdf = await pdfjsLib.getDocument({ url: this.url }).promise;
                this.pageCount = refs.pdf.numPages;
                await this.renderPage();
                this.buildThumbnails();
            } catch (error) {
                console.error('Failed to load PDF', error);
                this.error = true;
            } finally {
                this.loading = false;
            }
        },

        destroy() {
            this.cancelRender();
            // PDF.js v6 moved teardown onto the loading task — `PDFDocumentProxy` itself has no
            // `destroy()`. It resolves asynchronously, and a teardown race is harmless, so the
            // rejection is swallowed rather than surfacing as an unhandled promise error.
            refs.pdf?.loadingTask?.destroy().catch(() => {});
        },

        get scalePercent() {
            return Math.round(this.scale * 100);
        },

        /** Render the current page into the main canvas at the current zoom. */
        async renderPage() {
            if (!refs.pdf) {
                return;
            }

            const page = await refs.pdf.getPage(this.currentPage);
            const ratio = window.devicePixelRatio || 1;
            const viewport = page.getViewport({ scale: this.scale });
            this.viewport = viewport;

            const canvas = this.$refs.canvas;
            const context = canvas.getContext('2d');
            canvas.width = Math.floor(viewport.width * ratio);
            canvas.height = Math.floor(viewport.height * ratio);
            canvas.style.width = `${Math.floor(viewport.width)}px`;
            canvas.style.height = `${Math.floor(viewport.height)}px`;
            context.setTransform(ratio, 0, 0, ratio, 0, 0);

            this.cancelRender();
            refs.renderTask = page.render({ canvasContext: context, viewport });

            try {
                await refs.renderTask.promise;
            } catch (error) {
                if (error?.name !== 'RenderingCancelledException') {
                    throw error;
                }
            }
        },

        cancelRender() {
            if (refs.renderTask) {
                try {
                    refs.renderTask.cancel();
                } catch {
                    // Cancelling an already-finished task is a no-op we can ignore.
                }
                refs.renderTask = null;
            }
        },

        /** Build the left-hand thumbnail rail, one small canvas per page. */
        async buildThumbnails() {
            const rail = this.$refs.thumbs;
            if (!rail || !refs.pdf) {
                return;
            }

            rail.replaceChildren();

            for (let pageNumber = 1; pageNumber <= this.pageCount; pageNumber += 1) {
                const page = await refs.pdf.getPage(pageNumber);
                const viewport = page.getViewport({ scale: THUMBNAIL_SCALE });

                const canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                canvas.className = 'w-full rounded border border-zinc-200 dark:border-zinc-700';
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.page = String(pageNumber);
                button.className =
                    'block w-full cursor-pointer rounded p-1 text-center text-xs text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800';
                button.addEventListener('click', () => this.goTo(pageNumber));

                const label = document.createElement('span');
                label.textContent = String(pageNumber);
                label.className = 'mt-1 block';

                button.append(canvas, label);
                rail.append(button);
            }

            this.highlightThumbnail();
        },

        highlightThumbnail() {
            const rail = this.$refs.thumbs;
            if (!rail) {
                return;
            }

            rail.querySelectorAll('button[data-page]').forEach((button) => {
                const isCurrent = Number(button.dataset.page) === this.currentPage;
                button.classList.toggle('bg-zinc-100', isCurrent);
                button.classList.toggle('dark:bg-zinc-800', isCurrent);
                button.classList.toggle('font-semibold', isCurrent);
                button.classList.toggle('text-zinc-900', isCurrent);
                button.classList.toggle('dark:text-white', isCurrent);
                if (isCurrent) {
                    button.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        async goTo(pageNumber) {
            const target = Math.min(Math.max(1, pageNumber), this.pageCount);
            if (target === this.currentPage) {
                return;
            }
            this.currentPage = target;
            await this.renderPage();
            this.highlightThumbnail();
        },

        next() {
            this.goTo(this.currentPage + 1);
        },

        prev() {
            this.goTo(this.currentPage - 1);
        },

        async zoomIn() {
            this.scale = Math.min(MAX_SCALE, this.scale + ZOOM_STEP);
            await this.renderPage();
        },

        async zoomOut() {
            this.scale = Math.max(MIN_SCALE, this.scale - ZOOM_STEP);
            await this.renderPage();
        },

        async resetZoom() {
            this.scale = 1;
            await this.renderPage();
        },

        /**
         * Map a canvas point to PDF user space (used by the Phase 3 overlay editor).
         *
         * @returns {{ x: number, y: number }|null}
         */
        toPdfPoint(x, y) {
            return this.viewport ? screenToPdf(this.viewport, x, y) : null;
        },

        /**
         * Map a PDF user-space point to a canvas point (used by the Phase 3 overlay editor).
         *
         * @returns {{ x: number, y: number }|null}
         */
        toScreenPoint(x, y) {
            return this.viewport ? pdfToScreen(this.viewport, x, y) : null;
        },
    };
}
