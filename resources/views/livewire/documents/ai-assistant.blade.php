<div class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    {{-- Header --}}
    <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
        <div class="flex items-center gap-2">
            <flux:icon name="sparkles" class="size-5 text-lapis-600 dark:text-lapis-400" />
            <flux:heading size="sm">{{ __('AI Assistant') }}</flux:heading>
        </div>
        <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="aiOpen = false" :tooltip="__('Close')" />
    </div>

    {{-- Tabs --}}
    <div class="border-b border-zinc-200 px-3 py-2.5 dark:border-zinc-800">
        <div class="flex gap-0.5 rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
            @foreach (['chat' => __('Chat'), 'summarize' => __('Summarize'), 'translate' => __('Translate')] as $key => $label)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'flex-1 rounded-md px-2 py-1.5 text-sm font-medium transition',
                        'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' => $tab === $key,
                        'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- LLM backend toggle (local Ollama vs cloud Gemini); remembered across requests. --}}
    @if (count($this->providers) > 1)
        <div class="flex items-center gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-800">
            <flux:icon name="cpu-chip" class="size-4 text-zinc-400" />
            <span class="text-xs font-medium text-zinc-500">{{ __('Model') }}</span>
            <div class="ms-auto flex gap-0.5 rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                @foreach ($this->providers as $key => $meta)
                    <button
                        type="button"
                        wire:click="$set('provider', '{{ $key }}')"
                        title="{{ $meta['model'] }}"
                        @class([
                            'rounded-md px-2.5 py-1 text-xs font-medium transition',
                            'bg-white text-zinc-900 shadow dark:bg-zinc-700 dark:text-white' => $provider === $key,
                            'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' => $provider !== $key,
                        ])
                    >{{ $meta['label'] }}</button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Body --}}
    <div class="flex min-h-0 flex-1 flex-col">
        @if ($tab === 'chat')
            {{-- Messages --}}
            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3">
                @forelse ($this->messages as $message)
                    @if ($message->role === \App\Enums\AiMessageRole::User)
                        <div class="flex justify-end">
                            <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-lapis-600 px-3 py-2 text-sm text-white dark:bg-lapis-500">{{ $message->content }}</div>
                        </div>
                    @else
                        <div class="flex flex-col items-start gap-1">
                            <div class="max-w-[90%] whitespace-pre-wrap rounded-2xl rounded-bl-sm bg-zinc-100 px-3 py-2 text-sm text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">{{ $message->content }}</div>
                            @php $pages = data_get($message->meta, 'pages', []); @endphp
                            @if (! empty($pages))
                                <div class="px-1 text-xs text-zinc-500">
                                    {{ __('Sources:') }} {{ collect($pages)->map(fn ($p) => __('p. :n', ['n' => $p]))->join(', ') }}
                                </div>
                            @endif
                        </div>
                    @endif
                @empty
                    <div class="flex h-full flex-col items-center justify-center gap-2 text-center text-sm text-zinc-500">
                        <flux:icon name="chat-bubble-left-right" class="size-8 text-zinc-300 dark:text-zinc-600" />
                        <p>{{ __('Ask anything about this document.') }}</p>
                    </div>
                @endforelse

                <div wire:loading wire:target="ask" class="flex justify-start">
                    <div class="rounded-2xl bg-zinc-100 px-3 py-2 text-sm text-zinc-500 dark:bg-zinc-800">
                        <flux:icon name="arrow-path" class="mr-1 inline size-4 animate-spin" />{{ __('Thinking…') }}
                    </div>
                </div>
            </div>

            {{-- Input --}}
            <form wire:submit="ask" class="border-t border-zinc-200 px-3 py-3 dark:border-zinc-800">
                <flux:error name="question" />
                <div class="flex items-end gap-2">
                    <flux:textarea
                        wire:model="question"
                        rows="2"
                        :placeholder="__('Ask a question…')"
                        class="flex-1"
                    />
                    <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled" wire:target="ask" />
                </div>
                @if ($this->messages->isNotEmpty())
                    <div class="mt-2">
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="clearChat" wire:confirm="{{ __('Clear this conversation?') }}">
                            {{ __('Clear chat') }}
                        </flux:button>
                    </div>
                @endif
            </form>
        @elseif ($tab === 'summarize')
            <div class="flex min-h-0 flex-1 flex-col gap-3 px-4 py-3">
                <flux:text class="text-sm">{{ __('Summarize the whole document, or a single page.') }}</flux:text>

                <div class="flex items-end gap-2">
                    <flux:input
                        type="number"
                        wire:model="scopePage"
                        :label="__('Page')"
                        min="1"
                        :max="$document->activePageCount()"
                        :placeholder="__('All')"
                        class="w-24"
                    />
                    <flux:button wire:click="summarizeDocument" variant="primary" icon="document-text" wire:loading.attr="disabled" wire:target="summarizeDocument">
                        {{ __('Summarize') }}
                    </flux:button>
                </div>

                <flux:error name="summary" />

                <div wire:loading wire:target="summarizeDocument" class="text-sm text-zinc-500">
                    <flux:icon name="arrow-path" class="mr-1 inline size-4 animate-spin" />{{ __('Summarizing…') }}
                </div>

                @if ($summary !== null)
                    <div wire:loading.remove wire:target="summarizeDocument" class="flex min-h-0 flex-1 flex-col gap-2" x-data="{ text: @js($summary) }">
                        <div class="flex items-center justify-between">
                            <flux:text class="text-xs font-medium text-zinc-500">
                                {{ $summaryScope ? __('Summary (:scope)', ['scope' => $summaryScope]) : __('Summary') }}
                            </flux:text>
                            <flux:button size="sm" variant="ghost" icon="clipboard" x-on:click="navigator.clipboard.writeText(text)">{{ __('Copy') }}</flux:button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto whitespace-pre-wrap rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-sm leading-relaxed text-zinc-800 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-100">{{ $summary }}</div>
                    </div>
                @endif
            </div>
        @else
            <div class="flex min-h-0 flex-1 flex-col gap-3 px-4 py-3">
                <flux:text class="text-sm">{{ __('Translate the document text into another language.') }}</flux:text>

                <div class="flex items-end gap-2">
                    <flux:select wire:model="targetLanguage" :label="__('Language')" class="flex-1">
                        @foreach (['English', 'Indonesian', 'Spanish', 'French', 'German', 'Italian', 'Portuguese', 'Dutch', 'Japanese', 'Korean', 'Chinese', 'Arabic'] as $language)
                            <flux:select.option value="{{ $language }}">{{ __($language) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input
                        type="number"
                        wire:model="scopePage"
                        :label="__('Page')"
                        min="1"
                        :max="$document->activePageCount()"
                        :placeholder="__('All')"
                        class="w-24"
                    />
                </div>

                <flux:button wire:click="translateDocument" variant="primary" icon="language" class="self-start" wire:loading.attr="disabled" wire:target="translateDocument">
                    {{ __('Translate') }}
                </flux:button>

                <flux:error name="translation" />

                <div wire:loading wire:target="translateDocument" class="text-sm text-zinc-500">
                    <flux:icon name="arrow-path" class="mr-1 inline size-4 animate-spin" />{{ __('Translating…') }}
                </div>

                @if ($translation !== null)
                    <div wire:loading.remove wire:target="translateDocument" class="flex min-h-0 flex-1 flex-col gap-2" x-data="{ text: @js($translation) }">
                        <div class="flex items-center justify-between">
                            <flux:text class="text-xs font-medium text-zinc-500">
                                {{ $translationScope ? __('Translation (:scope)', ['scope' => $translationScope]) : __('Translation') }}
                            </flux:text>
                            <flux:button size="sm" variant="ghost" icon="clipboard" x-on:click="navigator.clipboard.writeText(text)">{{ __('Copy') }}</flux:button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto whitespace-pre-wrap rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-sm leading-relaxed text-zinc-800 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-100">{{ $translation }}</div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- Footer: honest "AI may be wrong" notice + model --}}
    <div class="border-t border-zinc-200 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800">
        {{ __('AI can make mistakes. Verify important details against the document.') }}
        @if ($this->model)
            <span class="text-zinc-400 dark:text-zinc-500">· {{ __('Powered by :model', ['model' => $this->model]) }}</span>
        @endif
    </div>
</div>
