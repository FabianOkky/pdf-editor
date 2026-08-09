<div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-lapis-600 dark:text-lapis-400">{{ __('Library') }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight">{{ __('Documents') }}</h1>
            <p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Upload, edit, organize and export. Originals are kept untouched.') }}</p>
        </div>

        <flux:modal.trigger name="upload">
            <flux:button variant="primary" icon="arrow-up-tray">{{ __('Upload PDF') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->documents->isEmpty())
        <div class="relative flex flex-1 flex-col items-center justify-center gap-5 overflow-hidden rounded-2xl border border-dashed border-zinc-300 p-14 text-center dark:border-zinc-700">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-30 [mask-image:radial-gradient(60%_60%_at_50%_50%,black,transparent)] dark:opacity-20"></div>
            <span class="relative flex size-14 items-center justify-center rounded-2xl bg-lapis-50 text-lapis-600 dark:bg-lapis-950 dark:text-lapis-400">
                <flux:icon name="document-plus" class="size-7" />
            </span>
            <div class="relative">
                <h2 class="text-lg font-semibold tracking-tight">{{ __('No documents yet') }}</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Drop in a PDF up to :mb MB and start editing right away.', ['mb' => (int) config('services.pdf.max_upload_mb')]) }}</p>
            </div>
            <flux:modal.trigger name="upload">
                <flux:button variant="primary" icon="arrow-up-tray" class="relative">{{ __('Upload PDF') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @else
        {{-- Selection bar: sticks under the header so a long library keeps the merge action reachable --}}
        @if (count($selected) > 0)
            <div class="sticky top-2 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-lapis-200 bg-lapis-50/90 p-3 shadow-sm backdrop-blur dark:border-lapis-800 dark:bg-lapis-950/80">
                <div class="flex items-center gap-2 text-sm font-medium text-lapis-900 dark:text-lapis-200">
                    <flux:icon name="check-circle" class="size-4" />
                    {{ trans_choice('{1} :count document selected|[2,*] :count documents selected', count($selected), ['count' => count($selected)]) }}
                </div>
                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="clearSelection">{{ __('Clear') }}</flux:button>
                    <flux:modal.trigger name="merge">
                        <flux:button size="sm" variant="primary" icon="document-duplicate" :disabled="count($selected) < 2">{{ __('Merge') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($this->documents as $document)
                @php($isSelected = in_array((string) $document->id, array_map('strval', $selected), true))

                <div
                    wire:key="document-{{ $document->id }}"
                    @class([
                        'group relative flex flex-col overflow-hidden rounded-xl border bg-white transition dark:bg-zinc-900',
                        'border-lapis-500 ring-2 ring-lapis-500/20' => $isSelected,
                        'border-zinc-200 hover:border-lapis-300 hover:shadow-lg hover:shadow-zinc-900/5 dark:border-zinc-800 dark:hover:border-lapis-700' => ! $isSelected,
                    ])
                >
                    {{-- The checkbox stays hidden until hover (or selection) so the grid reads as
                         documents first and a bulk-action tool second. --}}
                    <div @class([
                        'absolute left-2.5 top-2.5 z-10 rounded-md bg-white/90 p-1 shadow-sm backdrop-blur transition dark:bg-zinc-900/90',
                        'opacity-0 group-hover:opacity-100 focus-within:opacity-100' => ! $isSelected,
                    ])>
                        <flux:checkbox wire:model.live="selected" value="{{ $document->id }}" />
                    </div>

                    <a href="{{ route('documents.show', $document) }}" wire:navigate class="bg-stage relative flex aspect-[3/4] items-center justify-center overflow-hidden border-b border-zinc-200 dark:border-zinc-800">
                        @if (data_get($document->meta, 'thumbnail_path'))
                            <img src="{{ route('documents.thumbnail', $document) }}" alt="{{ $document->title }}" class="size-full object-cover object-top transition duration-300 group-hover:scale-[1.02]" loading="lazy" />
                        @else
                            <flux:icon name="document-text" class="size-16 text-zinc-300 dark:text-zinc-700" />
                        @endif

                        @if ($document->status === \App\Enums\DocumentStatus::Failed)
                            <flux:badge color="red" size="sm" class="absolute bottom-2 left-2">{{ __('Failed') }}</flux:badge>
                        @elseif ($document->latestVersion)
                            <span class="absolute bottom-2 left-2 rounded-md bg-white/90 px-2 py-0.5 text-[11px] font-medium text-lapis-700 shadow-sm backdrop-blur dark:bg-zinc-900/90 dark:text-lapis-300">
                                {{ __('v:number', ['number' => $document->latestVersion->version_number]) }}
                            </span>
                        @endif
                    </a>

                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('documents.show', $document) }}" wire:navigate class="truncate font-medium tracking-tight hover:underline" title="{{ $document->title }}">
                                {{ $document->title }}
                            </a>

                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" inset="top bottom" />

                                <flux:menu>
                                    <flux:menu.item href="{{ route('documents.show', $document) }}" wire:navigate icon="eye">{{ __('Open') }}</flux:menu.item>
                                    <flux:menu.item href="{{ route('documents.editor', $document) }}" wire:navigate icon="pencil-square">{{ __('Edit') }}</flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item href="{{ route('documents.download', $document) }}" icon="arrow-down-tray">{{ __('Download') }}</flux:menu.item>
                                    @if ($document->latestVersion)
                                        <flux:menu.item href="{{ route('documents.download.original', $document) }}" icon="archive-box">{{ __('Download original') }}</flux:menu.item>
                                    @endif
                                    <flux:menu.item wire:click="startRename({{ $document->id }})" icon="pencil">{{ __('Rename') }}</flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item wire:click="delete({{ $document->id }})" wire:confirm="{{ __('Delete “:title”? You can still find it in trash.', ['title' => $document->title]) }}" variant="danger" icon="trash">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        <div class="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-zinc-500 dark:text-zinc-500">
                            @php($activePages = $document->latestVersion?->page_count ?? $document->page_count)
                            <span>{{ trans_choice('{1} :count page|[2,*] :count pages', $activePages, ['count' => $activePages]) }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $document->created_at?->diffForHumans() }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div>
            {{ $this->documents->links() }}
        </div>
    @endif

    {{-- Upload modal --}}
    <flux:modal name="upload" class="md:w-[28rem]">
        <form wire:submit="save" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Upload a PDF') }}</flux:heading>
                <flux:text class="mt-2">{{ __('PDF files up to :mb MB. We analyse it once, then leave the bytes alone.', ['mb' => (int) config('services.pdf.max_upload_mb')]) }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('File') }}</flux:label>

                <input
                    type="file"
                    wire:model="file"
                    accept="application/pdf,.pdf"
                    class="block w-full cursor-pointer rounded-lg border border-dashed border-zinc-300 p-3 text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-lapis-600 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-lapis-700 dark:border-zinc-700 dark:text-zinc-400 dark:file:bg-lapis-500 dark:hover:file:bg-lapis-400"
                />

                <flux:error name="file" />
            </flux:field>

            <div wire:loading wire:target="file" class="flex items-center gap-2 text-sm text-zinc-500">
                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                {{ __('Uploading…') }}
            </div>
            <div wire:loading wire:target="save" class="flex items-center gap-2 text-sm text-zinc-500">
                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                {{ __('Analyzing PDF…') }}
            </div>

            <div class="flex items-center justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,save">{{ __('Upload') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Merge modal --}}
    <flux:modal name="merge" class="md:w-[30rem]">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Merge documents') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Combine these into one new document. Reorder as needed — your originals are kept.') }}</flux:text>
            </div>

            <div class="flex flex-col gap-2">
                @foreach ($this->selectedDocuments as $index => $selectedDocument)
                    <div wire:key="merge-{{ $selectedDocument->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span class="flex size-6 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">{{ $index + 1 }}</span>
                            <span class="truncate" title="{{ $selectedDocument->title }}">{{ $selectedDocument->title }}</span>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="moveUp({{ $index }})" :disabled="$loop->first" />
                            <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="moveDown({{ $index }})" :disabled="$loop->last" />
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:error name="merge" />

            <div class="flex items-center justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button wire:click="merge" variant="primary" icon="document-duplicate" wire:loading.attr="disabled" wire:target="merge">
                    {{ __('Merge') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Rename modal --}}
    <flux:modal name="rename" class="md:w-96">
        <form wire:submit="rename" class="flex flex-col gap-6">
            <flux:heading size="lg">{{ __('Rename document') }}</flux:heading>

            <flux:input wire:model="renameTitle" :label="__('Title')" required autofocus />

            <div class="flex items-center justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
