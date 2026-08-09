<x-layouts::app :title="__('Dashboard')">
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-8">
        {{-- Header --}}
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-lapis-600 dark:text-lapis-400">{{ __('Workspace') }}</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ __('Welcome back, :name', ['name' => auth()->user()->name]) }}</h1>
                <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Pick up where you left off — every original is right where you put it.') }}</p>
            </div>
            <flux:button :href="route('documents.index')" wire:navigate variant="primary" icon="arrow-up-tray">
                {{ __('Upload a PDF') }}
            </flux:button>
        </div>

        {{-- Stat strip: one bordered band rather than four floating cards, so it reads as a summary
             line instead of a dashboard cliché. --}}
        @php
            $cards = [
                ['document-text', __('Documents'), $stats['documents']],
                ['pencil-square', __('Edited'), $stats['edited']],
                ['rectangle-stack', __('Pages'), $stats['pages']],
                ['circle-stack', __('Storage'), $stats['storage']],
            ];
        @endphp

        <div class="grid gap-px overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-200 dark:border-zinc-800 dark:bg-zinc-800 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($cards as [$icon, $label, $value])
                <div class="flex items-center gap-4 bg-white p-5 dark:bg-zinc-900">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-lapis-50 text-lapis-600 dark:bg-lapis-950 dark:text-lapis-400">
                        <flux:icon :name="$icon" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-500">{{ $label }}</div>
                        <div class="mt-0.5 text-2xl font-semibold tracking-tight">{{ $value }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Recent documents --}}
        <div class="flex flex-1 flex-col gap-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold tracking-tight">{{ __('Recent documents') }}</h2>
                @if ($recentDocuments->isNotEmpty())
                    <flux:link :href="route('documents.index')" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
                @endif
            </div>

            @if ($recentDocuments->isEmpty())
                <div class="relative flex flex-1 flex-col items-center justify-center gap-5 overflow-hidden rounded-2xl border border-dashed border-zinc-300 p-14 text-center dark:border-zinc-700">
                    <div class="bg-grid pointer-events-none absolute inset-0 opacity-30 [mask-image:radial-gradient(60%_60%_at_50%_50%,black,transparent)] dark:opacity-20"></div>
                    <span class="relative flex size-14 items-center justify-center rounded-2xl bg-lapis-50 text-lapis-600 dark:bg-lapis-950 dark:text-lapis-400">
                        <flux:icon name="document-plus" class="size-7" />
                    </span>
                    <div class="relative">
                        <h3 class="text-lg font-semibold tracking-tight">{{ __('Nothing here yet') }}</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Upload a PDF and start editing — the original stays exactly as it is.') }}</p>
                    </div>
                    <flux:button :href="route('documents.index')" wire:navigate variant="primary" icon="arrow-up-tray" class="relative">
                        {{ __('Upload a PDF') }}
                    </flux:button>
                </div>
            @else
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($recentDocuments as $document)
                        <a
                            wire:key="recent-{{ $document->id }}"
                            href="{{ route('documents.show', $document) }}"
                            wire:navigate
                            class="group flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition hover:-translate-y-0.5 hover:border-lapis-300 hover:shadow-lg hover:shadow-zinc-900/5 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-lapis-700"
                        >
                            <div class="bg-stage relative flex aspect-[3/4] items-center justify-center overflow-hidden border-b border-zinc-200 dark:border-zinc-800">
                                @if (data_get($document->meta, 'thumbnail_path'))
                                    <img src="{{ route('documents.thumbnail', $document) }}" alt="{{ $document->title }}" class="size-full object-cover object-top transition duration-300 group-hover:scale-[1.02]" loading="lazy" />
                                @else
                                    <flux:icon name="document-text" class="size-16 text-zinc-300 dark:text-zinc-700" />
                                @endif
                            </div>
                            <div class="flex flex-col gap-1 p-4">
                                <div class="truncate font-medium tracking-tight" title="{{ $document->title }}">{{ $document->title }}</div>
                                @php($activePages = $document->latestVersion?->page_count ?? $document->page_count)
                                <div class="text-sm text-zinc-500 dark:text-zinc-500">
                                    {{ trans_choice('{1} :count page|[2,*] :count pages', $activePages, ['count' => $activePages]) }}
                                    · {{ $document->created_at?->diffForHumans() }}
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
