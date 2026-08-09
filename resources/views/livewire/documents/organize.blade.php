@php
    $latestVersion = $document->latestVersion;
    $activeUrl = $latestVersion
        ? route('documents.versions.file', [$document, $latestVersion])
        : route('documents.file', $document);
@endphp

<div class="mx-auto flex h-full w-full max-w-[110rem] flex-1 flex-col gap-4">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            <flux:button :href="route('documents.show', $document)" wire:navigate variant="ghost" size="sm" icon="arrow-left" inset="left">
                {{ __('Back') }}
            </flux:button>
            <div class="h-5 w-px bg-zinc-200 dark:bg-zinc-800"></div>
            <div class="min-w-0">
                <h1 class="truncate text-lg font-semibold tracking-tight">{{ __('Organize pages') }}</h1>
                <p class="truncate text-sm text-zinc-500 dark:text-zinc-500" title="{{ $document->title }}">{{ $document->title }}</p>
            </div>
        </div>
    </div>

    <div class="flex items-start gap-2.5 rounded-xl border border-zinc-200 bg-white p-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
        <flux:icon name="information-circle" class="mt-0.5 size-5 shrink-0 text-lapis-600 dark:text-lapis-400" />
        <span>{{ __('Drag to reorder, rotate or delete pages, then save. Pages are copied, never re-rendered — and your original stays untouched as changes land in a new version.') }}</span>
    </div>

    <flux:error name="pages" />

    {{-- Page manager (PDF.js + Alpine; kept out of Livewire morphing with wire:ignore) --}}
    <div
        wire:ignore
        x-data="pageManager({ url: @js($activeUrl) })"
        class="bg-stage flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800"
    >
        {{-- Toolbar --}}
        <div class="flex items-center justify-between gap-3 border-b border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500 dark:text-zinc-500">
                <span class="font-medium text-zinc-800 dark:text-zinc-200" x-text="count"></span> {{ __('pages') }}
                <span x-show="dirty" x-cloak class="ms-1.5 rounded-md bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ __('unsaved changes') }}</span>
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
            <div x-show="error" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-red-600 dark:text-red-400">
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
