/**
 * Overlay editor Alpine component (`pdfEditor`).
 *
 * Renders a PDF page with PDF.js and lets the user place non-destructive overlay edits on
 * top: text, whiteout, highlight, underline, strike, shapes (rect/ellipse/line), freehand,
 * and images. Each overlay stores its geometry in **PDF user space** (points, bottom-left
 * origin) so the Python `/pdf/bake` endpoint can flatten it deterministically regardless of
 * the on-screen zoom (ARCHITECTURE.md §3). The original document is never modified.
 *
 * The working overlay list is the source of truth here; it autosaves (debounced) to the
 * Livewire component via `$wire.syncOverlays(...)` and is baked into a new version on export.
 * We render overlays as plain DOM/SVG elements (no canvas library dependency), mirroring the
 * page-manager's native approach.
 */

import * as pdfjsLib from 'pdfjs-dist';

import { pdfToScreen, screenToPdf } from './coords';
import { ensurePdfWorker } from './worker';

const ZOOM_STEP = 0.25;
const MIN_SCALE = 0.25;
const MAX_SCALE = 4;
const AUTOSAVE_MS = 800;
const RECT_TOOLS = ['whiteout', 'highlight', 'underline', 'strike', 'rect', 'ellipse'];

/** Font stacks offered for a typed signature (rasterized client-side, so any system font works). */
const SIGNATURE_FONTS = [
    { label: 'Script', stack: "'Segoe Script', 'Bradley Hand', 'Brush Script MT', cursive" },
    { label: 'Brush', stack: "'Brush Script MT', 'Segoe Script', cursive" },
    { label: 'Serif', stack: "'Apple Chancery', 'URW Chancery L', 'Palatino Linotype', serif" },
];

/** AcroForm field types we seed as fillable form_field overlays (others are skipped). */
const FILLABLE_FIELD_TYPES = ['text', 'checkbox', 'radio', 'combobox', 'listbox'];
const CHECKBOX_FIELD_TYPES = ['checkbox', 'radio'];

/**
 * @param {{ url: string, pageCount?: number, overlays?: Array<object>, signatures?: Array<object> }} config
 */
export function pdfEditor({ url, pageCount = 0, overlays = [], signatures = [] }) {
    // PDF.js objects must NOT live in Alpine's reactive state. Alpine wraps reactive
    // properties in a Proxy, but PDF.js relies on private class fields (`#…`) whose brand
    // checks throw "Cannot read private member … from an object whose class did not declare
    // it" when the receiver is a Proxy instead of the real instance. Keep the document and
    // its render task in this non-reactive closure (referenced as `refs.*`, never `this.*`).
    // `viewport` (a plain PageViewport with no private fields) stays reactive so the template
    // getters that derive the editing surface size keep updating on zoom / page changes.
    const refs = { pdf: null, renderTask: null, textContent: null };

    return {
        url,
        pageCount,
        currentPage: 1,
        scale: 1,
        loading: true,
        error: false,
        saving: false,
        baking: false,

        tool: 'select',
        /** @type {Array<{ id: string, type: string, page_number: number, payload: object, z_index: number }>} */
        overlays: [],
        selectedId: null,

        // --- signatures & forms ---
        /** @type {Array<{ id: number, name: string, type: string, data: string }>} */
        signatures: signatures || [],
        signatureOpen: false,
        signatureTab: 'draw', // draw | type | upload
        signatureName: '',
        typeText: '',
        typeFont: SIGNATURE_FONTS[0].stack,
        /** @type {string|null} A data URL captured from the upload tab. */
        uploadData: null,
        padHasInk: false,
        detectingForms: false,
        formMessage: '',

        /** @type {Array<string>} */
        undoStack: [],
        /** @type {Array<string>} */
        redoStack: [],

        /** @type {import('pdfjs-dist').PageViewport|null} */
        viewport: null,
        /** @type {number|null} */
        autosaveTimer: null,
        /** @type {object|null} */
        draft: null,

        async init() {
            this.overlays = (overlays || []).map((overlay) => this.hydrate(overlay));
            try {
                await ensurePdfWorker();
                refs.pdf = await pdfjsLib.getDocument({ url: this.url }).promise;
                this.pageCount = refs.pdf.numPages;
                await this.renderPage();
            } catch (error) {
                console.error('Failed to load PDF for the editor', error);
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

        // --- rendering -------------------------------------------------------------------

        get scalePercent() {
            return Math.round(this.scale * 100);
        },

        /** Overlays belonging to the page currently on screen. */
        get pageOverlays() {
            return this.overlays.filter((overlay) => overlay.page_number === this.currentPage);
        },

        /** Rect-like overlays for this page (rendered as positioned DOM elements). */
        get rectOverlays() {
            return this.pageOverlays.filter(
                (overlay) => overlay.type !== 'freehand' && !(overlay.type === 'shape' && overlay.payload.kind === 'line'),
            );
        },

        /** Vector overlays for this page (lines + freehand, rendered as SVG). */
        get vectorOverlays() {
            return this.pageOverlays.filter(
                (overlay) => overlay.type === 'freehand' || (overlay.type === 'shape' && overlay.payload.kind === 'line'),
            );
        },

        /**
         * Line shapes on this page. Split out from {@see freehandOverlays} so each renders in
         * its own `<svg>` via a top-level `<template x-for>` — nesting `<template>` inside an
         * `<svg>` loses Alpine's x-for scope, so we never do that.
         */
        get lineOverlays() {
            return this.pageOverlays.filter(
                (overlay) => overlay.type === 'shape' && overlay.payload.kind === 'line',
            );
        },

        /** Freehand strokes on this page (rendered as an SVG polyline, one `<svg>` each). */
        get freehandOverlays() {
            return this.pageOverlays.filter((overlay) => overlay.type === 'freehand');
        },

        get surfaceWidth() {
            return this.viewport ? this.viewport.width : 0;
        },

        get surfaceHeight() {
            return this.viewport ? this.viewport.height : 0;
        },

        /** The currently selected overlay, if any. */
        get selected() {
            return this.overlays.find((overlay) => overlay.id === this.selectedId) || null;
        },

        /** Which payload key holds the editable color for the selected overlay (or null). */
        get colorKey() {
            const overlay = this.selected;
            if (!overlay || overlay.type === 'image' || overlay.type === 'signature') {
                return null;
            }

            return overlay.type === 'shape' || overlay.type === 'freehand' ? 'stroke' : 'color';
        },

        get isText() {
            return this.selected?.type === 'text';
        },

        get hasOpacity() {
            return ['text', 'highlight', 'shape', 'freehand', 'underline', 'strike', 'signature'].includes(this.selected?.type);
        },

        get hasStrokeWidth() {
            return ['shape', 'freehand', 'underline', 'strike'].includes(this.selected?.type);
        },

        /** The font choices offered on the "type" signature tab. */
        get signatureFonts() {
            return SIGNATURE_FONTS;
        },

        /** Whether the active signature tab currently holds something placeable. */
        get canUseSignature() {
            if (this.signatureTab === 'draw') {
                return this.padHasInk;
            }
            if (this.signatureTab === 'type') {
                return this.typeText.trim().length > 0;
            }

            return Boolean(this.uploadData);
        },

        /** Whether this page (or any page) already carries seeded form fields. */
        get hasFormFields() {
            return this.overlays.some((overlay) => overlay.type === 'form_field');
        },

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

            const surface = this.$refs.surface;
            surface.style.width = `${Math.floor(viewport.width)}px`;
            surface.style.height = `${Math.floor(viewport.height)}px`;

            this.cancelRender();
            refs.renderTask = page.render({ canvasContext: context, viewport });
            try {
                await refs.renderTask.promise;
            } catch (error) {
                if (error?.name !== 'RenderingCancelledException') {
                    throw error;
                }
            }

            // Cache this page's text runs (in PDF user space) so the "Edit text" tool can find
            // the run under a click and swap it for an editable overlay. Non-fatal if it fails.
            try {
                refs.textContent = await page.getTextContent();
            } catch {
                refs.textContent = null;
            }
        },

        cancelRender() {
            if (refs.renderTask) {
                try {
                    refs.renderTask.cancel();
                } catch {
                    // Cancelling a finished task is a harmless no-op.
                }
                refs.renderTask = null;
            }
        },

        /** Screen-space box ({left, top, width, height} CSS px) for a PDF-space rect payload. */
        rectToBox(payload) {
            // Existing overlays can render before the first page is laid out (viewport is set
            // asynchronously after the PDF loads). Return a zero box until then; Alpine re-runs
            // this once `viewport` becomes available.
            if (!this.viewport) {
                return { left: 0, top: 0, width: 0, height: 0 };
            }

            const a = pdfToScreen(this.viewport, payload.x, payload.y);
            const b = pdfToScreen(this.viewport, payload.x + payload.width, payload.y + payload.height);

            return {
                left: Math.min(a.x, b.x),
                top: Math.min(a.y, b.y),
                width: Math.abs(b.x - a.x),
                height: Math.abs(b.y - a.y),
            };
        },

        /** Screen-space endpoints for a line shape (x,y + signed width/height in PDF points). */
        lineToPoints(payload) {
            if (!this.viewport) {
                return { x1: 0, y1: 0, x2: 0, y2: 0 };
            }

            const a = pdfToScreen(this.viewport, payload.x, payload.y);
            const b = pdfToScreen(this.viewport, payload.x + payload.width, payload.y + payload.height);

            return { x1: a.x, y1: a.y, x2: b.x, y2: b.y };
        },

        /** Freehand points (PDF space) → an SVG polyline "x,y x,y …" string in screen px. */
        freehandPoints(payload) {
            if (!this.viewport) {
                return '';
            }

            return (payload.points || [])
                .map(([x, y]) => {
                    const p = pdfToScreen(this.viewport, x, y);

                    return `${p.x},${p.y}`;
                })
                .join(' ');
        },

        /** CSS inline style for a rect-like overlay element. */
        boxStyle(overlay) {
            const box = this.rectToBox(overlay.payload);

            return `left:${box.left}px;top:${box.top}px;width:${box.width}px;height:${box.height}px;`;
        },

        // --- coordinate helpers ----------------------------------------------------------

        /** Pointer event → CSS pixels relative to the overlay surface. */
        localPoint(event) {
            const rect = this.$refs.surface.getBoundingClientRect();

            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        },

        /** Pointer event → PDF user-space point. */
        pdfPoint(event) {
            const local = this.localPoint(event);

            return screenToPdf(this.viewport, local.x, local.y);
        },

        // --- tool interaction ------------------------------------------------------------

        setTool(tool) {
            this.tool = tool;
            if (tool !== 'select') {
                this.selectedId = null;
            }
        },

        /** Pointer down on the empty surface: start drawing with the active tool. */
        onSurfacePointerDown(event) {
            if (event.button !== 0 || this.tool === 'select' || this.tool === 'image') {
                return;
            }

            const start = this.pdfPoint(event);

            if (this.tool === 'text') {
                this.commit(() => this.addText(start));

                return;
            }

            if (this.tool === 'edittext') {
                this.editTextAt(event);

                return;
            }

            this.draft = { tool: this.tool, start, points: [[start.x, start.y]] };
            this.$refs.surface.setPointerCapture(event.pointerId);
        },

        onSurfacePointerMove(event) {
            if (!this.draft) {
                return;
            }

            const point = this.pdfPoint(event);

            if (this.draft.tool === 'freehand') {
                this.draft.points.push([point.x, point.y]);
            } else {
                this.draft.end = point;
            }

            this.draft.preview = this.draftPreview();
        },

        onSurfacePointerUp(event) {
            if (!this.draft) {
                return;
            }

            try {
                this.$refs.surface.releasePointerCapture(event.pointerId);
            } catch {
                // No active capture — ignore.
            }

            const overlay = this.draftToOverlay();
            this.draft = null;
            if (overlay) {
                this.commit(() => this.overlays.push(overlay));
                this.setTool('select');
            }
        },

        /** A live preview box/line for the in-progress draft (rendered while dragging). */
        draftPreview() {
            if (!this.draft || !this.draft.end) {
                return null;
            }

            const { start, end } = this.draft;
            if (this.draft.tool === 'line') {
                return { kind: 'line', ...this.lineToPoints({ x: start.x, y: start.y, width: end.x - start.x, height: end.y - start.y }) };
            }

            return { kind: 'rect', ...this.rectToBox(this.rectFromPoints(start, end)) };
        },

        /** Normalize two PDF points into a positive-extent rect payload. */
        rectFromPoints(a, b) {
            return {
                x: Math.min(a.x, b.x),
                y: Math.min(a.y, b.y),
                width: Math.abs(b.x - a.x),
                height: Math.abs(b.y - a.y),
            };
        },

        /** Turn the finished draft into an overlay object (or null if it was a stray click). */
        draftToOverlay() {
            const { tool, start, end, points } = this.draft;

            if (tool === 'freehand') {
                if (points.length < 2) {
                    return null;
                }

                return this.make('freehand', { points, stroke: '#ef4444', stroke_width: 2, opacity: 1 });
            }

            if (!end || (Math.abs(end.x - start.x) < 2 && Math.abs(end.y - start.y) < 2)) {
                return null;
            }

            if (tool === 'line') {
                return this.make('shape', {
                    kind: 'line',
                    x: start.x,
                    y: start.y,
                    width: end.x - start.x,
                    height: end.y - start.y,
                    stroke: '#111827',
                    stroke_width: 2,
                    opacity: 1,
                });
            }

            const rect = this.rectFromPoints(start, end);

            return this.make(tool, this.defaultPayload(tool, rect));
        },

        /** Click-to-place a default-sized text box at the pointer. */
        addText(at) {
            const overlay = this.make('text', {
                x: at.x,
                y: at.y - 18,
                width: 180,
                height: 22,
                text: '',
                font_size: 14,
                font: 'sans',
                color: '#111827',
                bold: false,
                italic: false,
                align: 'left',
                opacity: 1,
            });
            this.overlays.push(overlay);
            this.selectedId = overlay.id;
            this.tool = 'select';
            this.$nextTick(() => this.focusText(overlay.id));
        },

        /** Default style payload per rect-based tool, mapping the editor tool to a stored type. */
        defaultPayload(tool, rect) {
            switch (tool) {
                case 'whiteout':
                    return { ...rect, color: '#ffffff' };
                case 'highlight':
                    return { ...rect, color: '#fde047', opacity: 0.4 };
                case 'underline':
                    return { ...rect, color: '#111827', stroke_width: 1.5, opacity: 1 };
                case 'strike':
                    return { ...rect, color: '#111827', stroke_width: 1.5, opacity: 1 };
                case 'rect':
                    return { kind: 'rect', ...rect, stroke: '#111827', stroke_width: 2, fill: null, opacity: 1 };
                case 'ellipse':
                    return { kind: 'ellipse', ...rect, stroke: '#111827', stroke_width: 2, fill: null, opacity: 1 };
                default:
                    return { ...rect };
            }
        },

        /** Map an editor tool to the persisted overlay type. */
        typeForTool(tool) {
            if (tool === 'rect' || tool === 'ellipse' || tool === 'line') {
                return 'shape';
            }

            return tool;
        },

        make(tool, payload) {
            return {
                id: crypto.randomUUID(),
                type: this.typeForTool(tool),
                page_number: this.currentPage,
                payload,
                z_index: this.nextZ(),
            };
        },

        nextZ() {
            return this.overlays.reduce((max, overlay) => Math.max(max, overlay.z_index || 0), 0) + 1;
        },

        // --- image insertion -------------------------------------------------------------

        pickImage() {
            this.$refs.image.click();
        },

        async onImageChosen(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) {
                return;
            }

            let dataUrl;
            let dimensions;
            try {
                dataUrl = await this.readFile(file);
                dimensions = await this.imageSize(dataUrl);
            } catch {
                return; // Unreadable or undecodable image — nothing to place.
            }

            const scaleToFit = Math.min(1, 200 / dimensions.width);
            const width = dimensions.width * scaleToFit;
            const height = dimensions.height * scaleToFit;
            // Drop it near the top-left of the page (40 CSS px in from the top-left corner).
            const pdf = screenToPdf(this.viewport, 40, 40);

            const overlay = this.make('image', {
                x: pdf.x,
                y: pdf.y - height,
                width,
                height,
                data: dataUrl,
                opacity: 1,
            });
            this.commit(() => this.overlays.push(overlay));
            this.selectedId = overlay.id;
        },

        readFile(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        },

        imageSize(dataUrl) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
                img.onerror = reject;
                img.src = dataUrl;
            });
        },

        // --- selection, move & resize ----------------------------------------------------

        select(overlay, event) {
            if (this.tool !== 'select') {
                return;
            }
            event.stopPropagation();
            this.selectedId = overlay.id;
        },

        /**
         * Clear the selection only when the click landed on the empty page (the canvas or the
         * surface itself). Clicks that bubble up from an overlay element must NOT deselect it —
         * otherwise selecting an overlay and releasing the mouse would instantly drop it.
         */
        onSurfaceClick(event) {
            if (this.tool !== 'select') {
                return;
            }
            const target = event?.target;
            if (target === this.$refs.surface || target === this.$refs.canvas) {
                this.selectedId = null;
            }
        },

        /** Begin dragging the selected overlay (move) from a pointer on its element. */
        startMove(overlay, event) {
            if (this.tool !== 'select' || event.button !== 0) {
                return;
            }
            event.stopPropagation();
            this.selectedId = overlay.id;
            const origin = this.pdfPoint(event);
            const before = JSON.stringify(this.serialize());
            const snapshot = JSON.parse(JSON.stringify(overlay.payload));
            let moved = false;

            const move = (e) => {
                const now = this.pdfPoint(e);
                if (Math.abs(now.x - origin.x) > 0.5 || Math.abs(now.y - origin.y) > 0.5) {
                    moved = true;
                }
                this.translate(overlay, snapshot, now.x - origin.x, now.y - origin.y);
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                // A plain click (no drag) is just a selection — don't record history or save.
                if (moved) {
                    this.pushHistory(before);
                    this.scheduleSave();
                }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        /** Apply a PDF-space translation to an overlay's geometry from a snapshot. */
        translate(overlay, snapshot, dx, dy) {
            if (overlay.type === 'freehand') {
                overlay.payload.points = snapshot.points.map(([x, y]) => [x + dx, y + dy]);
            } else {
                overlay.payload.x = snapshot.x + dx;
                overlay.payload.y = snapshot.y + dy;
            }
        },

        /** Begin resizing a rect-based or image/text overlay via its bottom-right handle. */
        startResize(overlay, event) {
            event.stopPropagation();
            event.preventDefault();
            const origin = this.pdfPoint(event);
            const before = JSON.stringify(this.serialize());
            const snapshot = JSON.parse(JSON.stringify(overlay.payload));

            const move = (e) => {
                const now = this.pdfPoint(e);
                const dx = now.x - origin.x;
                const dy = now.y - origin.y;
                // Bottom-right in screen space = keep top-left fixed; PDF y grows upward.
                overlay.payload.width = Math.max(4, snapshot.width + dx);
                overlay.payload.height = Math.max(4, snapshot.height - dy);
                overlay.payload.y = snapshot.y + dy;
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                this.pushHistory(before);
                this.scheduleSave();
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        canResize(overlay) {
            return (
                overlay.type !== 'freehand' &&
                overlay.type !== 'form_field' &&
                !(overlay.type === 'shape' && overlay.payload.kind === 'line')
            );
        },

        /** Pointer down on a rect-like overlay: text/form fields edit in place; others move. */
        onRectPointerDown(overlay, event) {
            if (overlay.type === 'text') {
                this.select(overlay, event);

                return;
            }
            if (overlay.type === 'form_field') {
                return; // the inner input/checkbox handles its own pointer events
            }

            this.startMove(overlay, event);
        },

        isCheckboxField(overlay) {
            return overlay.type === 'form_field' && CHECKBOX_FIELD_TYPES.includes(overlay.payload.field_type);
        },

        isTruthy(value) {
            return value === true || ['true', 'yes', 'on', '1', 'x'].includes(String(value).toLowerCase());
        },

        // --- edit existing PDF text ------------------------------------------------------

        /**
         * Find the original PDF text run under a pointer event. PDF.js returns each run's
         * transform and width in PDF user space (points), the same space our overlays use, so
         * we hit-test directly against the click's PDF-space point. The run's font style block
         * (`content.styles[fontName]`) gives the real family + ascent/descent so the editable
         * overlay we drop in matches the original metrics instead of guessing.
         *
         * @returns {{ str: string, x: number, baseline: number, width: number, fontSize: number,
         *   ascent: number, descent: number, font: string, bold: boolean, italic: boolean }|null}
         */
        textItemAt(event) {
            const content = refs.textContent;
            if (!content || !this.viewport) {
                return null;
            }

            const point = this.pdfPoint(event);

            for (const item of content.items) {
                if (!item.str || !item.str.trim()) {
                    continue;
                }

                const t = item.transform; // [a, b, c, d, e, f] in PDF user space
                const fontSize = Math.hypot(t[2], t[3]);
                if (!(fontSize > 0)) {
                    continue;
                }

                const style = content.styles?.[item.fontName] || null;
                const x = t[4];
                const baseline = t[5];
                const width = item.width;
                // PDF.js reports ascent/descent as em fractions; fall back to typical values.
                const ascentFrac = style && style.ascent ? Math.abs(style.ascent) : 0.85;
                const descentFrac = style && style.descent ? Math.abs(style.descent) : 0.25;
                const ascent = fontSize * ascentFrac;
                const descent = fontSize * descentFrac;

                if (
                    point.x >= x &&
                    point.x <= x + width &&
                    point.y >= baseline - descent &&
                    point.y <= baseline + ascent
                ) {
                    return {
                        str: item.str,
                        x,
                        baseline,
                        width,
                        fontSize,
                        ascent,
                        descent,
                        font: this.genericFamily(style),
                        bold: this.looksBold(style),
                        italic: this.looksItalic(style),
                    };
                }
            }

            return null;
        },

        /** Map a PDF.js font style block to one of our three generic families (sans/serif/mono). */
        genericFamily(style) {
            const family = (style?.fontFamily || '').toLowerCase();
            if (family.includes('mono')) {
                return 'mono';
            }
            if (family.includes('serif') && !family.includes('sans')) {
                return 'serif';
            }

            return 'sans';
        },

        looksBold(style) {
            return /bold|black|heavy|semibold/.test((style?.fontFamily || '').toLowerCase());
        },

        looksItalic(style) {
            return /italic|oblique/.test((style?.fontFamily || '').toLowerCase());
        },

        /** A concrete CSS font stack for a generic family, used to render text overlays on screen. */
        fontStack(font) {
            if (font === 'serif') {
                return "Georgia, 'Times New Roman', Times, serif";
            }
            if (font === 'mono') {
                return "'Courier New', Courier, monospace";
            }

            return 'Arial, Helvetica, sans-serif';
        },

        /**
         * Click an existing text run to edit it: cover the original with a whiteout and drop an
         * editable text overlay pre-filled with the same text at the same position and size.
         * Non-destructive — the underlying page bytes are never touched (the Golden Rule).
         */
        editTextAt(event) {
            const hit = this.textItemAt(event);
            if (!hit) {
                this.noteForm('Click directly on existing text to edit it.');

                return;
            }

            const pad = Math.max(0.5, hit.fontSize * 0.1);
            const boxHeight = hit.ascent + hit.descent;

            const whiteout = this.make('whiteout', {
                x: hit.x - pad,
                y: hit.baseline - hit.descent - pad,
                width: hit.width + pad * 2,
                height: boxHeight + pad * 2,
                color: '#ffffff',
            });
            // The editable box must be at least one full line tall, or the on-screen text is
            // clipped (the run's real ascent+descent can be < 1em, while the CSS line-height is
            // ~1.25em — that made edited text look like it vanished). Grow the box DOWNWARD only:
            // its TOP stays at the original cap line (baseline + ascent), so the baked text lands
            // in exactly the same place regardless of this visual height.
            const boxTop = hit.baseline + hit.ascent;
            const editHeight = Math.max(boxHeight, hit.fontSize * 1.4);
            const text = this.make('text', {
                x: hit.x,
                y: boxTop - editHeight,
                width: Math.max(hit.width, 12),
                height: editHeight,
                text: hit.str,
                font_size: hit.fontSize,
                font: hit.font,
                color: '#000000',
                bold: hit.bold,
                italic: hit.italic,
                align: 'left',
                opacity: 1,
            });

            this.commit(() => this.overlays.push(whiteout, text));
            this.selectedId = text.id;
            this.tool = 'select';
            this.$nextTick(() => this.focusText(text.id));
        },

        // --- text editing ----------------------------------------------------------------

        focusText(id) {
            const el = this.$refs.surface.querySelector(`[data-text="${id}"]`);
            el?.focus();
        },

        onTextInput(overlay, event) {
            overlay.payload.text = event.target.innerText;
            this.scheduleSave();
        },

        // --- property panel updates ------------------------------------------------------

        updatePayload(key, value) {
            const overlay = this.selected;
            if (!overlay) {
                return;
            }
            const before = JSON.stringify(this.serialize());
            overlay.payload[key] = value;
            this.pushHistory(before);
            this.scheduleSave();
        },

        deleteSelected() {
            if (!this.selected) {
                return;
            }
            const id = this.selectedId;
            this.commit(() => {
                this.overlays = this.overlays.filter((overlay) => overlay.id !== id);
            });
            this.selectedId = null;
        },

        bringToFront() {
            const overlay = this.selected;
            if (!overlay) {
                return;
            }
            this.commit(() => {
                overlay.z_index = this.nextZ();
            });
        },

        // --- signatures ------------------------------------------------------------------

        openSignature() {
            this.signatureTab = 'draw';
            this.signatureName = '';
            this.typeText = '';
            this.uploadData = null;
            this.padHasInk = false;
            this.signatureOpen = true;
            this.$nextTick(() => this.resetPad());
        },

        closeSignature() {
            this.signatureOpen = false;
        },

        resetPad() {
            const canvas = this.$refs.pad;
            if (!canvas) {
                return;
            }
            const ratio = window.devicePixelRatio || 1;
            canvas.width = canvas.clientWidth * ratio;
            canvas.height = canvas.clientHeight * ratio;
            const ctx = canvas.getContext('2d');
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#111827';
            this._pad = ctx;
            this.padHasInk = false;
        },

        padPoint(event) {
            const rect = this.$refs.pad.getBoundingClientRect();

            return { x: event.clientX - rect.left, y: event.clientY - rect.top };
        },

        padDown(event) {
            if (event.button !== 0 || !this._pad) {
                return;
            }
            this.$refs.pad.setPointerCapture(event.pointerId);
            const p = this.padPoint(event);
            this._pad.beginPath();
            this._pad.moveTo(p.x, p.y);
            this._drawing = true;
        },

        padMove(event) {
            if (!this._drawing) {
                return;
            }
            const p = this.padPoint(event);
            this._pad.lineTo(p.x, p.y);
            this._pad.stroke();
            this.padHasInk = true;
        },

        padUp(event) {
            this._drawing = false;
            try {
                this.$refs.pad.releasePointerCapture(event.pointerId);
            } catch {
                // No active capture — ignore.
            }
        },

        clearPad() {
            const canvas = this.$refs.pad;
            this._pad?.clearRect(0, 0, canvas.width, canvas.height);
            this.padHasInk = false;
        },

        /** Rasterize the typed signature in the chosen font to a transparent PNG data URL. */
        renderTyped() {
            const text = this.typeText.trim();
            if (!text) {
                return null;
            }

            const fontSize = 64;
            const measure = document.createElement('canvas').getContext('2d');
            measure.font = `${fontSize}px ${this.typeFont}`;
            const width = Math.ceil(measure.measureText(text).width) + 40;
            const height = Math.ceil(fontSize * 1.6);

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.font = `${fontSize}px ${this.typeFont}`;
            ctx.fillStyle = '#111827';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, 20, height / 2);

            return canvas.toDataURL('image/png');
        },

        async onSignatureUpload(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file || !['image/png', 'image/jpeg'].includes(file.type)) {
                return;
            }
            try {
                this.uploadData = await this.readFile(file);
            } catch {
                this.uploadData = null;
            }
        },

        /** The data URL for the active signature tab, or null when nothing is ready yet. */
        currentSignatureData() {
            if (this.signatureTab === 'draw') {
                return this.padHasInk ? this.$refs.pad.toDataURL('image/png') : null;
            }
            if (this.signatureTab === 'type') {
                return this.renderTyped();
            }

            return this.uploadData;
        },

        /** Place a signature (image-like overlay) near the top-left of the current page. */
        async placeSignatureData(data) {
            let dimensions;
            try {
                dimensions = await this.imageSize(data);
            } catch {
                return;
            }

            const scaleToFit = Math.min(1, 200 / dimensions.width);
            const width = dimensions.width * scaleToFit;
            const height = dimensions.height * scaleToFit;
            const pdf = screenToPdf(this.viewport, 60, 60);

            const overlay = this.make('signature', {
                x: pdf.x,
                y: pdf.y - height,
                width,
                height,
                data,
                opacity: 1,
            });
            this.commit(() => this.overlays.push(overlay));
            this.selectedId = overlay.id;
            this.closeSignature();
        },

        addSignatureToPage() {
            const data = this.currentSignatureData();
            if (data) {
                this.placeSignatureData(data);
            }
        },

        placeSaved(signature) {
            this.placeSignatureData(signature.data);
        },

        async saveSignatureForReuse() {
            const data = this.currentSignatureData();
            if (!data) {
                return;
            }
            this.signatures = await this.$wire.saveSignature(data, this.signatureTab, this.signatureName);
            this.signatureName = '';
        },

        async removeSaved(signature) {
            this.signatures = await this.$wire.deleteSignature(signature.id);
        },

        // --- form fields -----------------------------------------------------------------

        async detectForms() {
            this.detectingForms = true;
            try {
                const result = await this.$wire.detectFormFields();
                const fields = (result?.fields || []).filter((field) => FILLABLE_FIELD_TYPES.includes(field.type));
                if (fields.length === 0) {
                    this.noteForm('No fillable form fields were found on this document.');

                    return;
                }
                this.seedFormFields(fields);
            } finally {
                this.detectingForms = false;
            }
        },

        /** Create a form_field overlay for each detected field not already on the page. */
        seedFormFields(fields) {
            const existing = new Set(
                this.overlays
                    .filter((overlay) => overlay.type === 'form_field')
                    .map((overlay) => `${overlay.page_number}:${overlay.payload.field_name}`),
            );

            const additions = [];
            for (const field of fields) {
                if (existing.has(`${field.page_number}:${field.name}`)) {
                    continue;
                }
                const isCheckbox = CHECKBOX_FIELD_TYPES.includes(field.type);
                additions.push({
                    id: crypto.randomUUID(),
                    type: 'form_field',
                    page_number: field.page_number,
                    z_index: this.nextZ(),
                    payload: {
                        x: field.x,
                        y: field.y,
                        width: field.width,
                        height: field.height,
                        field_name: field.name,
                        field_type: field.type,
                        value: isCheckbox ? this.isTruthy(field.value) : field.value || '',
                        options: field.options || [],
                        font_size: Math.min(16, Math.max(8, field.height * 0.6)),
                        color: '#111827',
                        align: 'left',
                        opacity: 1,
                    },
                });
            }

            if (additions.length === 0) {
                this.noteForm('Form fields are already on the page.');

                return;
            }

            this.commit(() => additions.forEach((overlay) => this.overlays.push(overlay)));
            this.noteForm(`Added ${additions.length} form field${additions.length === 1 ? '' : 's'} — fill them in and export.`);
            this.goTo(fields[0].page_number);
        },

        toggleFormCheckbox(overlay) {
            const before = JSON.stringify(this.serialize());
            overlay.payload.value = !this.isTruthy(overlay.payload.value);
            this.pushHistory(before);
            this.scheduleSave();
        },

        removeFormFields() {
            this.commit(() => {
                this.overlays = this.overlays.filter((overlay) => overlay.type !== 'form_field');
            });
            this.selectedId = null;
        },

        noteForm(message) {
            this.formMessage = message;
            if (this._formTimer) {
                clearTimeout(this._formTimer);
            }
            this._formTimer = setTimeout(() => {
                this.formMessage = '';
            }, 4000);
        },

        // --- pages & zoom ----------------------------------------------------------------

        async goTo(pageNumber) {
            const target = Math.min(Math.max(1, pageNumber), this.pageCount);
            if (target === this.currentPage) {
                return;
            }
            this.selectedId = null;
            this.currentPage = target;
            await this.renderPage();
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

        // --- history (undo / redo) -------------------------------------------------------

        /** Run a mutation, recording the prior state for undo and triggering autosave. */
        commit(mutator) {
            const before = JSON.stringify(this.serialize());
            mutator();
            this.pushHistory(before);
            this.scheduleSave();
        },

        pushHistory(snapshot) {
            this.undoStack.push(snapshot);
            if (this.undoStack.length > 50) {
                this.undoStack.shift();
            }
            this.redoStack = [];
        },

        undo() {
            if (this.undoStack.length === 0) {
                return;
            }
            this.redoStack.push(JSON.stringify(this.serialize()));
            this.restore(this.undoStack.pop());
        },

        redo() {
            if (this.redoStack.length === 0) {
                return;
            }
            this.undoStack.push(JSON.stringify(this.serialize()));
            this.restore(this.redoStack.pop());
        },

        restore(snapshot) {
            this.overlays = JSON.parse(snapshot).map((overlay) => this.hydrate(overlay));
            this.selectedId = null;
            this.scheduleSave();
        },

        // --- persistence -----------------------------------------------------------------

        hydrate(overlay) {
            return {
                id: overlay.id ? String(overlay.id) : crypto.randomUUID(),
                type: overlay.type,
                page_number: overlay.page_number,
                payload: overlay.payload,
                z_index: overlay.z_index || 0,
            };
        },

        /** The wire payload: overlays without their client-only ids, ordered by paint order. */
        serialize() {
            return this.overlays.map((overlay, index) => ({
                type: overlay.type,
                page_number: overlay.page_number,
                payload: overlay.payload,
                z_index: overlay.z_index || 0,
                order: index,
            }));
        },

        scheduleSave() {
            if (this.autosaveTimer) {
                clearTimeout(this.autosaveTimer);
            }
            this.autosaveTimer = setTimeout(() => this.persist(), AUTOSAVE_MS);
        },

        async persist() {
            if (this.autosaveTimer) {
                clearTimeout(this.autosaveTimer);
                this.autosaveTimer = null;
            }
            this.saving = true;
            try {
                await this.$wire.syncOverlays(this.serialize());
            } finally {
                this.saving = false;
            }
        },

        /** Flush pending edits, then ask Livewire to bake them into a downloadable version. */
        async saveAndBake() {
            this.baking = true;
            try {
                await this.persist();
                await this.$wire.bake();
            } finally {
                this.baking = false;
            }
        },
    };
}
