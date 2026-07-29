<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Services\PageOperationService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

/**
 * The page manager: reorder, rotate, and delete pages, then save the result as a new
 * version. The thumbnail grid + drag-and-drop runs client-side (PDF.js + Alpine); this
 * component receives the final page list and delegates to {@see PageOperationService}.
 */
class Organize extends Component
{
    public Document $document;

    public function mount(Document $document): void
    {
        $this->authorize('update', $document);

        $this->document = $document;
    }

    /**
     * Persist the reorganized pages as a new version. `$pages` is the desired final list,
     * in order, each `{source: <1-based original page>, rotate: <degrees>}`.
     *
     * @param  array<int, array{source?: mixed, rotate?: mixed}>  $pages
     */
    public function save(array $pages, PageOperationService $pageOperations): void
    {
        $this->authorize('update', $this->document);

        $pages = $this->sanitizePages($pages);

        if ($pages === []) {
            $this->addError('pages', __('Keep at least one page.'));

            return;
        }

        try {
            $pageOperations->organize($this->document, $pages, Auth::user());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('pages', __('We could not save your changes. Please try again.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Saved as a new version.'));
        $this->redirectRoute('documents.show', $this->document, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.documents.organize')->title(__('Organize :title', ['title' => $this->document->title]));
    }

    /**
     * Coerce and bound-check the incoming page list: integer sources within the active page
     * range, rotations normalized to 0/90/180/270. Anything invalid is dropped.
     *
     * @param  array<int, array{source?: mixed, rotate?: mixed}>  $pages
     * @return list<array{source: int, rotate: int}>
     */
    protected function sanitizePages(array $pages): array
    {
        $max = $this->document->activePageCount();
        $clean = [];

        foreach ($pages as $page) {
            $source = (int) ($page['source'] ?? 0);
            $rotate = ((int) ($page['rotate'] ?? 0)) % 360;

            if ($rotate < 0) {
                $rotate += 360;
            }

            if ($source >= 1 && $source <= $max && $rotate % 90 === 0) {
                $clean[] = ['source' => $source, 'rotate' => $rotate];
            }
        }

        return $clean;
    }
}
