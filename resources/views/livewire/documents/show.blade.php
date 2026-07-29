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
@endphp

<div
    @if ($exportPending) wire:poll.2s @endif
    x-data="{ aiOpen: false }"
    class="flex h-full w-full flex-1 flex-col gap-4"
>
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <flux:button :href="route('documents.index')" wire:navigate variant="ghost" size="sm" icon="arrow-left">
                {{ __('Library') }}
            </flux:button>
            <flux:heading class="truncate" title="{{ $document->title }}">{{ $document->title }}</flux:heading>
            <flux:badge :color="$sourceBadge['color']" size="sm">{{ $sourceBadge['label'] }}</flux:badge>
            @if ($this->versions->isNotEmpty())
                <flux:badge color="purple" size="sm">{{ __('Edited') }}</flux:badge>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <flux:button
                x-on:click="aiOpen = !aiOpen"
                x-bind:class="aiOpen && 'bg-zinc-100 dark:bg-zinc-800'"
                variant="ghost"
                size="sm"
                icon="sparkles"
            >{{ __('AI Assistant') }}</flux:button>

            <flux:button :href="route('documents.editor', $document)" wire:navigate variant="primary" size="sm" icon="pencil-square">
                {{ __('Edit') }}
            </flux:button>

            <flux:button :href="route('documents.organize', $document)" wire:navigate variant="ghost" size="sm" icon="squares-2x2">
                {{ __('Organize pages') }}
            </flux:button>

            @if ($canSplit)
                <flux:modal.trigger name="split">
                    <flux:button variant="ghost" size="sm" icon="scissors">{{ __('Split') }}</flux:button>
                </flux:modal.trigger>
            @endif

            <flux:modal.trigger name="versions">
                <flux:button variant="ghost" size="sm" icon="clock">
                    {{ __('Versions') }}@if ($this->versions->isNotEmpty()) ({{ $this->versions->count() }})@endif
                </flux:button>
            </flux:modal.trigger>

            <flux:modal.trigger name="export-word">
                <flux:button variant="ghost" size="sm" icon="document-text">
                    {{ __('Export to Word') }}
                    @if ($exportPending)
                        <flux:icon name="arrow-path" class="ml-1 inline size-4 animate-spin" />
                    @elseif ($export?->isDownloadable())
                        <flux:badge color="green" size="sm" class="ml-1">{{ __('Ready') }}</flux:badge>
                    @endif
                </flux:button>
            </flux:modal.trigger>

            <flux:button :href="route('documents.download', $document)" variant="ghost" size="sm" icon="arrow-down-tray">
                {{ __('Download') }}
            </flux:button>
        </div>
    </div>

    {{-- Viewer + AI Assistant side panel --}}
    <div class="flex min-h-0 flex-1 gap-4">
    {{-- Viewer (PDF.js renders here; kept out of Livewire's DOM diffing with wire:ignore) --}}
    <div
        wire:ignore
        x-data="pdfViewer({ url: @js($this->activeUrl), pageCount: {{ $activePageCount }} })"
        class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-950"
    >
        {{-- Controls --}}
        <div class="flex items-center justify-between gap-2 border-b border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-1">
                <flux:button size="sm" variant="ghost" icon="chevron-left" x-on:click="prev()" x-bind:disabled="currentPage <= 1" />
                <span class="min-w-24 text-center text-sm text-zinc-600 dark:text-zinc-300">
                    <span x-text="currentPage"></span> / <span x-text="pageCount"></span>
                </span>
                <flux:button size="sm" variant="ghost" icon="chevron-right" x-on:click="next()" x-bind:disabled="currentPage >= pageCount" />
            </div>

            <div class="flex items-center gap-1">
                <flux:button size="sm" variant="ghost" icon="magnifying-glass-minus" x-on:click="zoomOut()" />
                <button type="button" x-on:click="resetZoom()" class="min-w-14 text-center text-sm text-zinc-600 hover:underline dark:text-zinc-300">
                    <span x-text="scalePercent"></span>%
                </button>
                <flux:button size="sm" variant="ghost" icon="magnifying-glass-plus" x-on:click="zoomIn()" />
            </div>
        </div>

        {{-- Body: thumbnail rail + page canvas --}}
        <div class="flex min-h-0 flex-1">
            <div x-ref="thumbs" class="hidden w-40 shrink-0 space-y-2 overflow-y-auto border-e border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-900 md:block"></div>

            <div class="relative flex-1 overflow-auto p-4">
                <div x-show="loading" class="absolute inset-0 flex items-center justify-center text-sm text-zinc-500">
                    <flux:icon name="arrow-path" class="mr-2 size-5 animate-spin" />{{ __('Loading document…') }}
                </div>
                <div x-show="error" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-red-600">
                    {{ __('We could not display this document.') }}
                </div>
                <div class="mx-auto w-fit">
                    <canvas x-ref="canvas" class="shadow-lg"></canvas>
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
                <flux:text class="mt-2">{{ __('Each edit is saved as a new version. The original upload is always preserved.') }}</flux:text>
            </div>

            <div class="flex flex-col gap-2">
                {{-- Original --}}
                <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="min-w-0">
                        <flux:heading size="sm">{{ __('Original') }}</flux:heading>
                        <flux:text class="text-xs">{{ trans_choice('{1} :count page|[2,*] :count pages', $document->page_count, ['count' => $document->page_count]) }}</flux:text>
                    </div>
                    <flux:button :href="route('documents.download', $document)" size="sm" variant="ghost" icon="arrow-down-tray">
                        {{ __('Download') }}
                    </flux:button>
                </div>

                {{-- Derived versions, newest first --}}
                @foreach ($this->versions as $version)
                    <div wire:key="version-{{ $version->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
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
            </div>
        </div>
    </flux:modal>

    {{-- Export to Word modal --}}
    <flux:modal name="export-word" class="md:w-[32rem]">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Export to Word') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Convert this PDF to an editable .docx. Scanned pages are run through OCR first, so their text comes through — the step most free converters skip. This is best-effort: text and rough layout transfer well, but it is not a pixel-perfect copy of the original.') }}
                </flux:text>
            </div>

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
                <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:icon name="arrow-path" class="size-5 shrink-0 animate-spin text-zinc-500" />
                    <div>
                        <flux:heading size="sm">{{ __('Preparing your document…') }}</flux:heading>
                        <flux:text class="text-xs">
                            {{ __('Scanned files take a little longer (OCR). You can keep working — this runs in the background.') }}
                        </flux:text>
                    </div>
                </div>
            @else
                <div class="flex flex-col gap-4">
                    <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
                        <div class="font-medium">{{ __('Your Word document is ready.') }}</div>
                        @if (data_get($export->meta, 'ocr_applied'))
                            <div class="mt-1 text-xs">{{ __('This document was scanned, so we used OCR to recover the text.') }}</div>
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
