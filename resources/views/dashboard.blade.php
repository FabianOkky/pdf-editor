<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        {{-- Header --}}
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Welcome back, :name', ['name' => auth()->user()->name]) }}</flux:heading>
                <flux:text class="mt-1">{{ __('Your PDFs, edited live and kept non-destructive.') }}</flux:text>
            </div>
            <flux:button :href="route('documents.index')" wire:navigate variant="primary" icon="document-text">
                {{ __('Open library') }}
            </flux:button>
        </div>

        {{-- Stat cards --}}
        <div class="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $cards = [
                    ['document-text', __('Documents'), $stats['documents']],
                    ['pencil-square', __('Edited'), $stats['edited']],
                    ['rectangle-stack', __('Pages'), $stats['pages']],
                    ['circle-stack', __('Storage'), $stats['storage']],
                ];
            @endphp

            @foreach ($cards as [$icon, $label, $value])
                <div class="flex items-center gap-4 rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-zinc-900">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        <flux:icon :name="$icon" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <flux:text class="text-sm">{{ $label }}</flux:text>
                        <flux:heading size="lg">{{ $value }}</flux:heading>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Recent documents --}}
        <div class="flex flex-1 flex-col gap-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Recent documents') }}</flux:heading>
                @if ($recentDocuments->isNotEmpty())
                    <flux:link :href="route('documents.index')" wire:navigate>{{ __('View all') }}</flux:link>
                @endif
            </div>

            @if ($recentDocuments->isEmpty())
                <div class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-zinc-300 p-12 text-center dark:border-zinc-700">
                    <flux:icon name="document-plus" class="size-12 text-zinc-400" />
                    <div>
                        <flux:heading size="lg">{{ __('No documents yet') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Upload your first PDF to start editing.') }}</flux:text>
                    </div>
                    <flux:button :href="route('documents.index')" wire:navigate variant="primary" icon="arrow-up-tray">
                        {{ __('Upload a PDF') }}
                    </flux:button>
                </div>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($recentDocuments as $document)
                        <a
                            wire:key="recent-{{ $document->id }}"
                            href="{{ route('documents.show', $document) }}"
                            wire:navigate
                            class="group flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600"
                        >
                            <div class="relative flex aspect-[3/4] items-center justify-center overflow-hidden border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                @if (data_get($document->meta, 'thumbnail_path'))
                                    <img src="{{ route('documents.thumbnail', $document) }}" alt="{{ $document->title }}" class="size-full object-cover object-top" loading="lazy" />
                                @else
                                    <flux:icon name="document-text" class="size-16 text-zinc-300 dark:text-zinc-600" />
                                @endif
                            </div>
                            <div class="flex flex-col gap-1 p-4">
                                <flux:heading class="truncate" title="{{ $document->title }}">{{ $document->title }}</flux:heading>
                                @php($activePages = $document->latestVersion?->page_count ?? $document->page_count)
                                <flux:text class="text-sm">
                                    {{ trans_choice('{1} :count page|[2,*] :count pages', $activePages, ['count' => $activePages]) }}
                                    · {{ $document->created_at?->diffForHumans() }}
                                </flux:text>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
