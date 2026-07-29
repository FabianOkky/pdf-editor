@php
    $latestVersion = $document->latestVersion;
    $activeUrl = $latestVersion
        ? route('documents.versions.file', [$document, $latestVersion])
        : route('documents.file', $document);
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-4">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <flux:button :href="route('documents.show', $document)" wire:navigate variant="ghost" size="sm" icon="arrow-left">
                {{ __('Back') }}
            </flux:button>
            <div class="min-w-0">
                <flux:heading class="truncate" title="{{ $document->title }}">{{ __('Organize pages') }}</flux:heading>
                <flux:text class="truncate">{{ $document->title }}</flux:text>
            </div>
        </div>
    </div>

    <div class="flex items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
        <flux:icon name="information-circle" class="mt-0.5 size-5 shrink-0 text-zinc-400" />
        <span>{{ __('Drag to reorder, rotate or delete pages, then save. Your original stays untouched — changes are saved as a new version.') }}</span>
    </div>

    <flux:error name="pages" />

    {{-- Page manager (PDF.js + Alpine; kept out of Livewire morphing with wire:ignore) --}}
    <div
        wire:ignore
        x-data="pageManager({ url: @js($activeUrl) })"
        class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-950"
    >
        {{-- Toolbar --}}
        <div class="flex items-center justify-between gap-3 border-b border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                <span x-text="count"></span> {{ __('pages') }}
                <span x-show="dirty" x-cloak class="ml-1 text-amber-600 dark:text-amber-500">· {{ __('unsaved changes') }}</span>
            </div>

            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" icon="arrow-path" x-on:click="reset()" x-bind:disabled="!dirty || saving">
                    {{ __('Reset') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="primary"
                    icon="check"
                    x-on:click="save()"
                    x-bind:disabled="saving || count === 0"
                >
                    <span x-show="!saving">{{ __('Save as new version') }}</span>
                    <span x-show="saving" x-cloak>{{ __('Saving…') }}</span>
                </flux:button>
            </div>
        </div>

        {{-- Body --}}
        <div class="relative min-h-0 flex-1 overflow-auto p-4">
            <div x-show="loading" class="absolute inset-0 flex items-center justify-center text-sm text-zinc-500">
                <flux:icon name="arrow-path" class="mr-2 size-5 animate-spin" />{{ __('Loading pages…') }}
            </div>
            <div x-show="error" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-red-600">
                {{ __('We could not load this document.') }}
            </div>
            <div x-show="!loading && count === 0" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-zinc-500">
                {{ __('No pages left. Reset to start over.') }}
            </div>

            <div
                x-ref="grid"
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6"
            ></div>
        </div>
    </div>
</div>
