import { pdfEditor } from './pdf-editor/editor';
import { pageManager } from './pdf-editor/page-manager';
import { pdfViewer } from './pdf-editor/viewer';

// Register the PDF.js Alpine components. Livewire ships and boots Alpine, so we hook
// `alpine:init` (fired before Alpine starts) to make `x-data="pdfViewer(...)"` /
// `x-data="pageManager(...)"` / `x-data="pdfEditor(...)"` available.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('pdfViewer', pdfViewer);
    window.Alpine.data('pageManager', pageManager);
    window.Alpine.data('pdfEditor', pdfEditor);
});
