/**
 * Screen ↔ PDF coordinate helpers.
 *
 * The browser works in viewport/canvas pixels (origin top-left, scaled by the current zoom).
 * PDF user space is in points with the origin at the bottom-left. The overlay editor
 * (Phase 3) stores geometry in PDF points so the Python service can bake it deterministically
 * regardless of zoom (see ARCHITECTURE.md §3). PDF.js page viewports already know how to map
 * between the two — these thin wrappers give the rest of the app a stable, documented API.
 *
 * @typedef {{ x: number, y: number }} Point
 */

/**
 * Convert a viewport/canvas point (CSS pixels at the current scale) to a PDF-space point.
 *
 * @param {import('pdfjs-dist').PageViewport} viewport
 * @param {number} x
 * @param {number} y
 * @returns {Point}
 */
export function screenToPdf(viewport, x, y) {
    const [pdfX, pdfY] = viewport.convertToPdfPoint(x, y);

    return { x: pdfX, y: pdfY };
}

/**
 * Convert a PDF-space point to a viewport/canvas point (CSS pixels at the current scale).
 *
 * @param {import('pdfjs-dist').PageViewport} viewport
 * @param {number} x
 * @param {number} y
 * @returns {Point}
 */
export function pdfToScreen(viewport, x, y) {
    const [screenX, screenY] = viewport.convertToViewportPoint(x, y);

    return { x: screenX, y: screenY };
}
