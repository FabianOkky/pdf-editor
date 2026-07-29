<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Documents') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Upload, view, and manage your PDFs.') }}</flux:text>
        </div>

        <flux:modal.trigger name="upload">
            <flux:button variant="primary" icon="arrow-up-tray">{{ __('Upload PDF') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->documents->isEmpty())
        <div class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-zinc-300 p-12 text-center dark:border-zinc-700">
            <flux:icon name="document-text" class="size-12 text-zinc-400" />
            <div>
                <flux:heading size="lg">{{ __('No documents yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Upload your first PDF to get started.') }}</flux:text>
            </div>
            <flux:modal.trigger name="upload">
                <flux:button variant="primary" icon="arrow-up-tray">{{ __('Upload PDF') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @else
        @if (count($selected) > 0)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text>{{ trans_choice('{1} :count document selected|[2,*] :count documents selected', count($selected), ['count' => count($selected)]) }}</flux:text>
                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="clearSelection">{{ __('Clear') }}</flux:button>
                    <flux:modal.trigger name="merge">
                        <flux:button size="sm" variant="primary" icon="document-duplicate" :disabled="count($selected) < 2">{{ __('Merge') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($this->documents as $document)
                <div wire:key="document-{{ $document->id }}" class="group relative flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:checkbox wire:model.live="selected" value="{{ $document->id }}" class="absolute left-2 top-2 z-10 bg-white/80 dark:bg-zinc-900/80" />

                    <a href="{{ route('documents.show', $document) }}" wire:navigate class="relative flex aspect-[3/4] items-center justify-center overflow-hidden border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        @if (data_get($document->meta, 'thumbnail_path'))
                            <img src="{{ route('documents.thumbnail', $document) }}" alt="{{ $document->title }}" class="size-full object-cover object-top" loading="lazy" />
                        @else
                            <flux:icon name="document-text" class="size-16 text-zinc-300 dark:text-zinc-600" />
                        @endif

                        @if ($document->status === \App\Enums\DocumentStatus::Failed)
                            <flux:badge color="red" size="sm" class="absolute left-2 top-2">{{ __('Failed') }}</flux:badge>
                        @endif
                    </a>

                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <flux:heading class="truncate" title="{{ $document->title }}">{{ $document->title }}</flux:heading>

                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" inset="top bottom" />

                                <flux:menu>
                                    <flux:menu.item href="{{ route('documents.show', $document) }}" wire:navigate icon="eye">{{ __('Open') }}</flux:menu.item>
                                    <flux:menu.item href="{{ route('documents.download', $document) }}" icon="arrow-down-tray">{{ __('Download') }}</flux:menu.item>
                                    <flux:menu.item wire:click="startRename({{ $document->id }})" icon="pencil-square">{{ __('Rename') }}</flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:menu.item wire:click="delete({{ $document->id }})" wire:confirm="{{ __('Delete “:title”? You can still find it in trash.', ['title' => $document->title]) }}" variant="danger" icon="trash">{{ __('Delete') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        <div class="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                            @php($activePages = $document->latestVersion?->page_count ?? $document->page_count)
                            <span>{{ trans_choice('{1} :count page|[2,*] :count pages', $activePages, ['count' => $activePages]) }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $document->created_at?->diffForHumans() }}</span>
                            @if ($document->latestVersion)
                                <flux:badge color="purple" size="sm">{{ __('Edited') }}</flux:badge>
                            @endif
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
                <flux:text class="mt-2">{{ __('PDF files up to :mb MB.', ['mb' => (int) config('services.pdf.max_upload_mb')]) }}</flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('File') }}</flux:label>

                <input
                    type="file"
                    wire:model="file"
                    accept="application/pdf,.pdf"
                    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600"
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
                    <div wire:key="merge-{{ $selectedDocument->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="text-sm text-zinc-400">{{ $index + 1 }}.</span>
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
