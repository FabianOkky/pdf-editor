/**
 * Page-manager Alpine component (`pageManager`): a thumbnail grid for reordering, rotating
 * and deleting pages before saving them as a new version.
 *
 * Thumbnails render client-side with PDF.js; reordering uses native HTML5 drag-and-drop (no
 * extra dependency). On save we hand the Livewire component the final page list — each entry
 * is `{ source: <1-based original page>, rotate: <degrees> }` — and it bakes the new version
 * through the Python service. Pages omitted from the list are deletions.
 */

import * as pdfjsLib from 'pdfjs-dist';

import { ensurePdfWorker } from './worker';

const THUMBNAIL_SCALE = 0.4;

/**
 * @param {{ url: string }} config
 */
export function pageManager({ url }) {
    // The PDF.js document must stay OUT of Alpine's reactive state: Alpine wraps reactive
    // properties in a Proxy, and PDF.js relies on private class fields (`#…`) whose brand
    // checks throw "Cannot read private member …" when the receiver is a Proxy. Hold it in
    // this non-reactive closure (referenced as `refs.pdf`, never `this.pdf`).
    const refs = { pdf: null };

    return {
        url,
        loading: true,
        error: false,
        saving: false,
        dirty: false,
        count: 0,

        /** @type {Array<{ source: number, rotate: number, canvas: HTMLCanvasElement, el: HTMLElement }>} */
        items: [],
        /** @type {number|null} */
        dragIndex: null,

        async init() {
            try {
                await ensurePdfWorker();
                refs.pdf = await pdfjsLib.getDocument({ url: this.url }).promise;
                await this.build();
            } catch (error) {
                console.error('Failed to load PDF for the page manager', error);
                this.error = true;
            } finally {
                this.loading = false;
            }
        },

        destroy() {
            // PDF.js v6 moved teardown onto the loading task — `PDFDocumentProxy` itself has no
            // `destroy()`. It resolves asynchronously, and a teardown race is harmless, so the
            // rejection is swallowed rather than surfacing as an unhandled promise error.
            refs.pdf?.loadingTask?.destroy().catch(() => {});
        },

        /** Render every page into a draggable tile and reset the working state. */
        async build() {
            const grid = this.$refs.grid;
            grid.replaceChildren();
            this.items = [];

            for (let pageNumber = 1; pageNumber <= refs.pdf.numPages; pageNumber += 1) {
                const page = await refs.pdf.getPage(pageNumber);
                const viewport = page.getViewport({ scale: THUMBNAIL_SCALE });

                const canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                canvas.className = 'max-h-full max-w-full rounded shadow-sm transition-transform duration-200';
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

                const item = { source: pageNumber, rotate: 0, canvas, el: null };
                item.el = this.buildTile(item);
                this.items.push(item);
                grid.append(item.el);
            }

            this.dirty = false;
            this.count = this.items.length;
        },

        /** Build one tile: the page canvas plus rotate/delete controls, wired for drag-drop. */
        buildTile(item) {
            const tile = document.createElement('div');
            tile.draggable = true;
            tile.className =
                'group relative flex flex-col items-center gap-2 rounded-lg border border-zinc-200 bg-white p-2 ' +
                'dark:border-zinc-700 dark:bg-zinc-900';

            const stage = document.createElement('div');
            stage.className =
                'flex h-44 w-full items-center justify-center overflow-hidden rounded bg-zinc-50 dark:bg-zinc-800';
            stage.append(item.canvas);

            const controls = document.createElement('div');
            controls.className = 'flex w-full items-center justify-between gap-1';

            const label = document.createElement('span');
            label.className = 'cursor-grab select-none text-xs text-zinc-400';
            label.textContent = `#${item.source}`;

            const buttons = document.createElement('div');
            buttons.className = 'flex items-center gap-1';
            buttons.append(
                this.iconButton('rotate-left', () => this.rotate(item, -90), 'Rotate left'),
                this.iconButton('rotate-right', () => this.rotate(item, 90), 'Rotate right'),
                this.iconButton('delete', () => this.remove(item), 'Delete page'),
            );

            controls.append(label, buttons);
            tile.append(stage, controls);

            tile.addEventListener('dragstart', (event) => {
                this.dragIndex = this.items.indexOf(item);
                event.dataTransfer.effectAllowed = 'move';
                tile.classList.add('opacity-40');
            });
            tile.addEventListener('dragend', () => {
                tile.classList.remove('opacity-40');
                this.dragIndex = null;
            });
            tile.addEventListener('dragover', (event) => {
                event.preventDefault();
                if (this.dragIndex === null) {
                    return;
                }
                const overIndex = this.items.indexOf(item);
                if (overIndex !== -1 && overIndex !== this.dragIndex) {
                    this.move(this.dragIndex, overIndex);
                    this.dragIndex = overIndex;
                }
            });
            tile.addEventListener('drop', (event) => event.preventDefault());

            return tile;
        },

        /** Small round control button rendered with an inline SVG icon. */
        iconButton(icon, onClick, title) {
            const button = document.createElement('button');
            button.type = 'button';
            button.title = title;
            button.setAttribute('aria-label', title);
            button.className =
                'flex size-7 items-center justify-center rounded text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 ' +
                'dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white';
            button.innerHTML = ICONS[icon];
            button.addEventListener('click', onClick);

            return button;
        },

        /** Move a tile within the array and reflect the new order in the DOM. */
        move(from, to) {
            if (from === to) {
                return;
            }
            const [moved] = this.items.splice(from, 1);
            this.items.splice(to, 0, moved);
            this.items.forEach((entry) => this.$refs.grid.append(entry.el));
            this.dirty = true;
        },

        /** Rotate a page by a 90° delta, previewing the change on its canvas. */
        rotate(item, delta) {
            item.rotate = (((item.rotate + delta) % 360) + 360) % 360;
            item.canvas.style.transform = `rotate(${item.rotate}deg)`;
            this.dirty = true;
        },

        /** Remove a page from the working set (it can be brought back with Reset). */
        remove(item) {
            const index = this.items.indexOf(item);
            if (index === -1) {
                return;
            }
            this.items.splice(index, 1);
            item.el.remove();
            this.dirty = true;
            this.count = this.items.length;
        },

        /** Discard all changes and rebuild from the original document. */
        reset() {
            this.build();
        },

        /** The final page list handed to the Livewire `save` action. */
        payload() {
            return this.items.map((item) => ({ source: item.source, rotate: item.rotate }));
        },

        async save() {
            if (this.items.length === 0) {
                return;
            }
            this.saving = true;
            try {
                await this.$wire.save(this.payload());
            } finally {
                this.saving = false;
            }
        },
    };
}

// Inline Heroicons (outline) so tiles don't depend on a Blade icon component.
const ICONS = {
    'rotate-left':
        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg>',
    'rotate-right':
        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15 15 6-6m0 0-6-6m6 6H9a6 6 0 0 0 0 12h3" /></svg>',
    delete:
        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>',
};
