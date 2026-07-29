/**
 * PDF.js worker setup, shared by the viewer, page-manager and overlay editor.
 *
 * PDF.js v6 ships an ESM worker (`pdf.worker.min.mjs`). When the app runs from a production
 * build, the static server (e.g. a default Herd/nginx) may serve `.mjs` as
 * `application/octet-stream`, which browsers reject for module workers ("Strict MIME type
 * checking is enforced for module scripts"). To be independent of server MIME configuration we
 * fetch the worker once and hand PDF.js a Blob URL with an explicit JavaScript MIME type. The
 * worker bundle is self-contained (no imports), so a Blob module worker loads cleanly.
 *
 * In dev (Vite serves the `.mjs` with the correct MIME) the fetch still succeeds, so the same
 * path works everywhere; any failure falls back to the direct URL.
 */

import * as pdfjsLib from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

/** @type {Promise<void>|null} Cached so the worker is set up at most once per page. */
let workerReady = null;

/**
 * Ensure `pdfjsLib.GlobalWorkerOptions.workerSrc` is set before any `getDocument` call.
 * Idempotent and cached — safe to await from every component's `init()`.
 *
 * @returns {Promise<void>}
 */
export function ensurePdfWorker() {
    if (!workerReady) {
        workerReady = (async () => {
            // In dev, Vite serves the worker `.mjs` with a correct MIME type but also injects its
            // HMR client (`import "/@vite/client"`) into every served module. That bare/absolute
            // specifier cannot resolve inside a Blob module worker, so the Blob trick below fails
            // ("Setting up fake worker"). Use the URL directly in dev; keep the MIME-safe Blob
            // path for production builds (where a static server may mislabel `.mjs`).
            if (import.meta.env?.DEV) {
                pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

                return;
            }

            try {
                const response = await fetch(workerUrl);
                if (!response.ok) {
                    throw new Error(`Worker fetch failed: ${response.status}`);
                }
                const source = await response.text();
                const blob = new Blob([source], { type: 'text/javascript' });
                pdfjsLib.GlobalWorkerOptions.workerSrc = URL.createObjectURL(blob);
            } catch {
                pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;
            }
        })();
    }

    return workerReady;
}
