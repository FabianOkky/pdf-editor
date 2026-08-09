@php
    $sourceBadge = [
        \App\Enums\DocumentSourceType::Native->value => ['label' => __('Native text'), 'color' => 'green'],
        \App\Enums\DocumentSourceType::Scanned->value => ['label' => __('Scanned'), 'color' => 'amber'],
        \App\Enums\DocumentSourceType::Mixed->value => ['label' => __('Mixed'), 'color' => 'blue'],
        \App\Enums\DocumentSourceType::Unknown->value => ['label' => __('Unknown'), 'color' => 'zinc'],
    ][$document->source_type->value];

    $activePageCount = $document->activePageCount();
    $canSplit = $activePageCount > 1;

    $export = $this->latestExport;
    $exportPending = $export && $export->status->isPending();
    $pendingEdits = $this->pendingEditCount;
@endphp

<div
    @if ($exportPending) wire:poll.2s @endif
    x-data="{ aiOpen: false }"
    class="mx-auto flex h-full w-full max-w-[110rem] flex-1 flex-col gap-4"
>
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <flux:button :href="route('documents.index')" wire:navigate variant="ghost" size="sm" icon="arrow-left" inset="left">
                {{ __('Library') }}
            </flux:button>
            <div class="h-5 w-px bg-zinc-200 dark:bg-zinc-800"></div>
            <h1 class="truncate text-lg font-semibold tracking-tight" title="{{ $document->title }}">{{ $document->title }}</h1>
            <flux:badge :color="$sourceBadge['color']" size="sm">{{ $sourceBadge['label'] }}</flux:badge>
            @if ($this->versions->isNotEmpty())
                <flux:badge color="purple" size="sm">{{ __('v:number', ['number' => $this->versions->first()->version_number]) }}</flux:badge>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <flux:button
                x-on:click="aiOpen = !aiOpen"
                x-bind:class="aiOpen && 'bg-lapis-50 text-lapis-700 dark:bg-lapis-950 dark:text-lapis-300'"
                variant="ghost"
                size="sm"
                icon="sparkles"
            >{{ __('AI Assistant') }}</flux:button>

            <flux:button :href="route('documents.organize', $document)" wire:navigate variant="ghost" size="sm" icon="squares-2x2">
                {{ __('Organize') }}
            </flux:button>

            @if ($canSplit)
                <flux:modal.trigger name="split">
                    <flux:button variant="ghost" size="sm" icon="scissors">{{ __('Split') }}</flux:button>
                </flux:modal.trigger>
            @endif

            <flux:modal.trigger name="versions">
                <flux:button variant="ghost" size="sm" icon="clock">
                    {{ __('Versions') }}@if ($this->versions->isNotEmpty()) <span class="text-zinc-400">({{ $this->versions->count() }})</span>@endif
                </flux:button>
            </flux:modal.trigger>

            <flux:modal.trigger name="export-word">
                <flux:button variant="ghost" size="sm" icon="document-text">
                    {{ __('Word') }}
                    @if ($exportPending)
                        <flux:icon name="arrow-path" class="ml-1 inline size-4 animate-spin" />
                    @elseif ($export?->isDownloadable())
                        <span class="ml-1 size-1.5 rounded-full bg-emerald-500"></span>
                    @endif
                </flux:button>
            </flux:modal.trigger>

            <flux:button wire:click="downloadEdited" variant="ghost" size="sm" icon="arrow-down-tray" wire:loading.attr="disabled" wire:target="downloadEdited">
                {{ __('Download') }}
            </flux:button>

            <div class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-800"></div>

            <flux:button :href="route('documents.editor', $document)" wire:navigate variant="primary" size="sm" icon="pencil-square">
                {{ __('Edit') }}
            </flux:button>
        </div>
    </div>

    {{-- Unapplied edits.
         The viewer renders the document's *bytes*, and overlay edits only reach the bytes once
         they are flattened — so without this banner an edited-then-exported document looks like
         the edits vanished. Applying is one click from here. --}}
    @if ($pendingEdits > 0)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <div class="flex items-start gap-3">
                <flux:icon name="exclamation-triangle" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                <div class="text-sm">
                    <div class="font-semibold text-amber-900 dark:text-amber-200">
                        {{ trans_choice('{1} :count edit is saved but not applied yet|[2,*] :count edits are saved but not applied yet', $pendingEdits, ['count' => $pendingEdits]) }}
                    </div>
                    <p class="mt-0.5 text-amber-800 dark:text-amber-300/90">
                        {{ __('Apply them to stamp your changes onto a new version — downloads, Word exports and this preview all read the applied file.') }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <flux:button :href="route('documents.editor', $document)" wire:navigate size="sm" variant="ghost">{{ __('Back to editor') }}</flux:button>
                <flux:button wire:click="applyEditsAndRefresh" size="sm" variant="primary" icon="check" wire:loading.attr="disabled" wire:target="applyEditsAndRefresh">
                    {{ __('Apply edits') }}
                </flux:button>
            </div>
        </div>
    @endif

    <flux:error name="apply" />

    {{-- Viewer + AI Assistant side panel --}}
    <div class="flex min-h-0 flex-1 gap-4">
        {{-- Viewer (PDF.js renders here; kept out of Livewire's DOM diffing with wire:ignore) --}}
        <div
            wire:ignore
            x-data="pdfViewer({ url: @js($this->activeUrl), pageCount: {{ $activePageCount }} })"
            class="bg-stage flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800"
        >
            {{-- Controls --}}
            <div class="flex items-center justify-between gap-2 border-b border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="chevron-left" x-on:click="prev()" x-bind:disabled="currentPage <= 1" />
                    <span class="min-w-24 text-center text-sm tabular-nums text-zinc-600 dark:text-zinc-400">
                        <span x-text="currentPage"></span> / <span x-text="pageCount"></span>
                    </span>
                    <flux:button size="sm" variant="ghost" icon="chevron-right" x-on:click="next()" x-bind:disabled="currentPage >= pageCount" />
                </div>

                <div class="flex items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="magnifying-glass-minus" x-on:click="zoomOut()" />
                    <button type="button" x-on:click="resetZoom()" class="min-w-14 rounded-md px-1 py-0.5 text-center text-sm tabular-nums text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                        <span x-text="scalePercent"></span>%
                    </button>
                    <flux:button size="sm" variant="ghost" icon="magnifying-glass-plus" x-on:click="zoomIn()" />
                </div>
            </div>

            {{-- Body: thumbnail rail + page canvas --}}
            <div class="flex min-h-0 flex-1">
                <div x-ref="thumbs" class="hidden w-40 shrink-0 space-y-2 overflow-y-auto border-e border-zinc-200 bg-white p-2 dark:border-zinc-800 dark:bg-zinc-900 md:block"></div>

                <div class="relative flex-1 overflow-auto p-6">
                    <div x-show="loading" class="absolute inset-0 flex items-center justify-center text-sm text-zinc-500">
                        <flux:icon name="arrow-path" class="mr-2 size-5 animate-spin" />{{ __('Loading document…') }}
                    </div>
                    <div x-show="error" x-cloak class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-red-600 dark:text-red-400">
                        {{ __('We could not display this document.') }}
                    </div>
                    <div class="mx-auto w-fit">
                        <canvas x-ref="canvas" class="rounded-sm shadow-2xl shadow-black/20"></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- AI Assistant side panel (toggled from the toolbar) --}}
        <div x-show="aiOpen" x-cloak class="w-full shrink-0 md:w-96 md:max-w-md">
            <livewire:documents.ai-assistant :document="$document" wire:key="ai-assistant-{{ $document->id }}" />
        </div>
    </div>

    {{-- Split modal --}}
    @if ($canSplit)
        <flux:modal name="split" class="md:w-[30rem]">
            <form wire:submit="split" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Split document') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Create new documents from this :count-page file. The original is kept.', ['count' => $activePageCount]) }}</flux:text>
                </div>

                <flux:radio.group wire:model.live="splitMode" :label="__('How to split')">
                    <flux:radio value="every" :label="__('Every N pages')" />
                    <flux:radio value="ranges" :label="__('Specific page ranges')" />
                </flux:radio.group>

                @if ($splitMode === 'every')
                    <flux:input
                        type="number"
                        wire:model="splitEvery"
                        :label="__('Pages per document')"
                        min="1"
                        :max="$activePageCount - 1"
                    />
                @else
                    <flux:input
                        wire:model="splitRanges"
                        :label="__('Page ranges')"
                        :placeholder="__('e.g. 1-2, 3-5, 7')"
                    />
                @endif

                <flux:error name="split" />

                <div class="flex items-center justify-end gap-3">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="split">
                        {{ __('Split') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Versions modal --}}
    <flux:modal name="versions" class="md:w-[32rem]">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Versions') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Each applied edit is saved as a new version. The original upload is always preserved, byte for byte.') }}</flux:text>
            </div>

            <div class="flex flex-col gap-2">
                {{-- Derived versions, newest first --}}
                @foreach ($this->versions as $version)
                    <div wire:key="version-{{ $version->id }}" @class([
                        'flex items-center justify-between gap-3 rounded-lg border p-3',
                        'border-lapis-300 bg-lapis-50/60 dark:border-lapis-800 dark:bg-lapis-950/40' => $loop->first,
                        'border-zinc-200 dark:border-zinc-800' => ! $loop->first,
                    ])>
                        <div class="min-w-0">
                            <flux:heading size="sm">
                                {{ __('Version :number', ['number' => $version->version_number]) }}
                                @if ($loop->first)
                                    <flux:badge color="green" size="sm" class="ml-1">{{ __('Current') }}</flux:badge>
                                @endif
                            </flux:heading>
                            <flux:text class="truncate text-xs">
                                {{ $version->label }} · {{ trans_choice('{1} :count page|[2,*] :count pages', $version->page_count, ['count' => $version->page_count]) }} · {{ $version->created_at?->diffForHumans() }}
                            </flux:text>
                        </div>

                        <flux:dropdown class="shrink-0">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />

                            <flux:menu>
                                <flux:menu.item :href="route('documents.versions.file', [$document, $version])" target="_blank" icon="eye">{{ __('View') }}</flux:menu.item>
                                <flux:menu.item :href="route('documents.versions.download', [$document, $version])" icon="arrow-down-tray">{{ __('Download') }}</flux:menu.item>
                                @unless ($loop->first)
                                    <flux:menu.separator />
                                    <flux:menu.item
                                        wire:click="restoreVersion({{ $version->id }})"
                                        wire:confirm="{{ __('Restore version :number as the latest?', ['number' => $version->version_number]) }}"
                                        icon="arrow-uturn-left"
                                    >{{ __('Restore as latest') }}</flux:menu.item>
                                @endunless
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                @endforeach

                {{-- The immutable original, always last and always available --}}
                <div class="flex items-center justify-between gap-3 rounded-lg border border-dashed border-zinc-300 p-3 dark:border-zinc-700">
                    <div class="min-w-0">
                        <flux:heading size="sm" class="flex items-center gap-1.5">
                            <flux:icon name="lock-closed" class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                            {{ __('Original upload') }}
                        </flux:heading>
                        <flux:text class="text-xs">
                            {{ trans_choice('{1} :count page|[2,*] :count pages', $document->page_count, ['count' => $document->page_count]) }} · {{ __('never modified') }}
                        </flux:text>
                    </div>
                    <flux:button :href="route('documents.download.original', $document)" size="sm" variant="ghost" icon="arrow-down-tray">
                        {{ __('Download') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </flux:modal>

    {{-- Export to Word modal --}}
    <flux:modal name="export-word" class="md:w-[32rem]">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Export to Word') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Convert this PDF to an editable .docx. Native pages convert layout-aware, scanned pages are OCR’d first, and mixed files get both — page by page. Best-effort: text and rough layout transfer well, but it is not a pixel-perfect copy.') }}
                </flux:text>
            </div>

            @if ($pendingEdits > 0)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                    {{ trans_choice(
                        '{1} Your :count unapplied edit will be applied first, so it appears in the Word file.|[2,*] Your :count unapplied edits will be applied first, so they appear in the Word file.',
                        $pendingEdits,
                        ['count' => $pendingEdits],
                    ) }}
                </div>
            @endif

            @if (! $export || $export->status === \App\Enums\ExportJobStatus::Failed)
                @if ($export && $export->status === \App\Enums\ExportJobStatus::Failed)
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
                        {{ $export->error ?? __('Something went wrong. Please try again.') }}
                    </div>
                @endif

                <flux:button
                    wire:click="exportToWord"
                    variant="primary"
                    icon="document-text"
                    class="self-start"
                    wire:loading.attr="disabled"
                    wire:target="exportToWord"
                >
                    {{ $export ? __('Try again') : __('Generate .docx') }}
                </flux:button>
            @elseif ($export->status->isPending())
                <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <flux:icon name="arrow-path" class="size-5 shrink-0 animate-spin text-lapis-600 dark:text-lapis-400" />
                    <div>
                        <flux:heading size="sm">{{ __('Preparing your document…') }}</flux:heading>
                        <flux:text class="text-xs">
                            {{ __('Scanned files take a little longer (OCR). You can keep working — this runs in the background.') }}
                        </flux:text>
                    </div>
                </div>
            @else
                <div class="flex flex-col gap-4">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <div class="font-medium">{{ __('Your Word document is ready.') }}</div>
                        @if (data_get($export->meta, 'ocr_applied'))
                            <div class="mt-1 text-xs">{{ __('Some pages were scanned, so we used OCR to recover their text.') }}</div>
                        @endif
                    </div>

                    <div class="flex items-center gap-3">
                        <flux:button
                            :href="route('documents.exports.download', [$document, $export])"
                            variant="primary"
                            icon="arrow-down-tray"
                        >
                            {{ __('Download .docx') }}
                        </flux:button>
                        <flux:button
                            wire:click="exportToWord"
                            variant="ghost"
                            wire:loading.attr="disabled"
                            wire:target="exportToWord"
                        >
                            {{ __('Regenerate') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
