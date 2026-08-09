@php
    $latestVersion = $document->latestVersion;
    $activeUrl = $latestVersion
        ? route('documents.versions.file', [$document, $latestVersion])
        : route('documents.file', $document);

    // Drawing tools: editor tool key → icon + label. `image` and `select` are handled apart.
    $drawTools = [
        ['tool' => 'text', 'icon' => 'document-text', 'label' => __('Text')],
        ['tool' => 'whiteout', 'icon' => 'stop', 'label' => __('Whiteout')],
        ['tool' => 'highlight', 'icon' => 'paint-brush', 'label' => __('Highlight')],
        ['tool' => 'underline', 'icon' => 'minus', 'label' => __('Underline')],
        ['tool' => 'strike', 'icon' => 'minus', 'label' => __('Strikethrough')],
        ['tool' => 'rect', 'icon' => 'stop', 'label' => __('Rectangle')],
        ['tool' => 'ellipse', 'icon' => 'stop-circle', 'label' => __('Ellipse')],
        ['tool' => 'line', 'icon' => 'minus', 'label' => __('Line')],
        ['tool' => 'freehand', 'icon' => 'pencil', 'label' => __('Freehand')],
    ];
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
                <h1 class="truncate text-lg font-semibold tracking-tight">{{ __('Edit document') }}</h1>
                <p class="truncate text-sm text-zinc-500 dark:text-zinc-500" title="{{ $document->title }}">{{ $document->title }}</p>
            </div>
        </div>
    </div>

    <div class="flex items-start gap-2.5 rounded-xl border border-zinc-200 bg-white p-3 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
        <flux:icon name="information-circle" class="mt-0.5 size-5 shrink-0 text-lapis-600 dark:text-lapis-400" />
        <span>
            {{ __('Edits sit on a layer above the page and autosave as you work — your original is never touched. Hit “Apply edits” to stamp them onto a new version; until you do, downloads and Word exports still show the unedited file.') }}
        </span>
    </div>

    <flux:error name="overlays" />
    <flux:error name="bake" />

    {{-- Editor (PDF.js + Alpine; kept out of Livewire morphing with wire:ignore) --}}
    <div
        wire:ignore
        x-data="pdfEditor({ url: @js($activeUrl), pageCount: {{ $document->activePageCount() }}, overlays: @js($this->overlays), signatures: @js($this->savedSignatures) })"
        class="bg-stage flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800"
    >
        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-white px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center gap-1">
                <flux:button size="sm" icon="cursor-arrow-rays" :tooltip="__('Select')" x-bind:variant="tool === 'select' ? 'primary' : 'ghost'" x-on:click="setTool('select')" />
                <flux:button size="sm" icon="pencil-square" :tooltip="__('Edit existing text')" x-bind:variant="tool === 'edittext' ? 'primary' : 'ghost'" x-on:click="setTool('edittext')" />

                <flux:separator vertical class="mx-1 h-6" />

                @foreach ($drawTools as $t)
                    <flux:button
                        size="sm"
                        icon="{{ $t['icon'] }}"
                        tooltip="{{ $t['label'] }}"
                        x-bind:variant="tool === '{{ $t['tool'] }}' ? 'primary' : 'ghost'"
                        x-on:click="setTool('{{ $t['tool'] }}')"
                    />
                @endforeach

                <flux:button size="sm" icon="photo" :tooltip="__('Image')" variant="ghost" x-on:click="pickImage()" />

                <flux:separator vertical class="mx-1 h-6" />

                <flux:button size="sm" icon="finger-print" :tooltip="__('Signature')" variant="ghost" x-on:click="openSignature()" />
                <flux:button size="sm" icon="document-check" :tooltip="__('Detect form fields')" variant="ghost" x-on:click="detectForms()" x-bind:disabled="detectingForms" />
                <flux:button size="sm" icon="x-circle" :tooltip="__('Remove form fields')" variant="ghost" x-show="hasFormFields" x-cloak x-on:click="removeFormFields()" />

                <flux:separator vertical class="mx-1 h-6" />

                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" :tooltip="__('Undo')" x-on:click="undo()" x-bind:disabled="undoStack.length === 0" />
                <flux:button size="sm" variant="ghost" icon="arrow-uturn-right" :tooltip="__('Redo')" x-on:click="redo()" x-bind:disabled="redoStack.length === 0" />
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs text-zinc-400" x-show="saving" x-cloak>{{ __('Saving…') }}</span>

                <div class="flex items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="chevron-left" x-on:click="prev()" x-bind:disabled="currentPage <= 1" />
                    <span class="min-w-20 text-center text-sm text-zinc-600 dark:text-zinc-300">
                        <span x-text="currentPage"></span> / <span x-text="pageCount"></span>
                    </span>
                    <flux:button size="sm" variant="ghost" icon="chevron-right" x-on:click="next()" x-bind:disabled="currentPage >= pageCount" />
                </div>

                <div class="flex items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="magnifying-glass-minus" x-on:click="zoomOut()" />
                    <button type="button" x-on:click="resetZoom()" class="min-w-12 text-center text-sm text-zinc-600 hover:underline dark:text-zinc-300">
                        <span x-text="scalePercent"></span>%
                    </button>
                    <flux:button size="sm" variant="ghost" icon="magnifying-glass-plus" x-on:click="zoomIn()" />
                </div>

                <flux:button size="sm" variant="primary" icon="check" x-on:click="saveAndBake()" x-bind:disabled="baking || overlays.length === 0">
                    <span x-show="!baking">{{ __('Apply edits') }}</span>
                    <span x-show="baking" x-cloak>{{ __('Applying…') }}</span>
                </flux:button>
            </div>
        </div>

        {{-- Transient form-detection feedback --}}
        <div x-show="formMessage" x-cloak x-transition class="border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300" x-text="formMessage"></div>

        {{-- Body: canvas surface + properties panel --}}
        <div class="flex min-h-0 flex-1">
            <div class="relative flex-1 overflow-auto p-6">
                <div x-show="loading" class="absolute inset-0 flex items-center justify-center text-sm text-zinc-500">
                    <flux:icon name="arrow-path" class="mr-2 size-5 animate-spin" />{{ __('Loading document…') }}
                </div>
                <div x-show="error" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-red-600">
                    {{ __('We could not display this document.') }}
                </div>

                <div class="mx-auto w-fit">
                    {{-- The editing surface: the page canvas with the overlay layer on top. --}}
                    <div
                        x-ref="surface"
                        class="relative touch-none select-none"
                        x-bind:class="tool === 'select' ? 'cursor-default' : 'cursor-crosshair'"
                        x-on:pointerdown="onSurfacePointerDown($event)"
                        x-on:pointermove="onSurfacePointerMove($event)"
                        x-on:pointerup="onSurfacePointerUp($event)"
                        x-on:click="onSurfaceClick($event)"
                    >
                        <canvas x-ref="canvas" class="block shadow-lg"></canvas>

                        {{-- Rect-like overlays (text, whiteout, highlight, underline, strike, shapes, image) --}}
                        <template x-for="overlay in rectOverlays" :key="overlay.id">
                            <div
                                class="absolute"
                                x-bind:style="boxStyle(overlay)"
                                x-bind:class="{ 'outline outline-2 outline-lapis-500': overlay.id === selectedId, 'cursor-move': tool === 'select' && overlay.type !== 'text' && overlay.type !== 'form_field', 'outline-dashed outline-1 outline-sky-400/80': overlay.type === 'form_field' && overlay.id !== selectedId }"
                                x-on:pointerdown="onRectPointerDown(overlay, $event)"
                            >
                                {{-- whiteout --}}
                                <div x-show="overlay.type === 'whiteout'" class="h-full w-full" x-bind:style="`background-color:${overlay.payload.color}`"></div>

                                {{-- highlight --}}
                                <div x-show="overlay.type === 'highlight'" class="h-full w-full" x-bind:style="`background-color:${overlay.payload.color};opacity:${overlay.payload.opacity}`"></div>

                                {{-- underline --}}
                                <div x-show="overlay.type === 'underline'" class="absolute inset-x-0 bottom-0" x-bind:style="`height:${Math.max(1, overlay.payload.stroke_width * scale)}px;background-color:${overlay.payload.color};opacity:${overlay.payload.opacity}`"></div>

                                {{-- strikethrough --}}
                                <div x-show="overlay.type === 'strike'" class="absolute inset-x-0 top-1/2 -translate-y-1/2" x-bind:style="`height:${Math.max(1, overlay.payload.stroke_width * scale)}px;background-color:${overlay.payload.color};opacity:${overlay.payload.opacity}`"></div>

                                {{-- shape: rectangle / ellipse --}}
                                <div
                                    x-show="overlay.type === 'shape'"
                                    class="h-full w-full"
                                    x-bind:class="overlay.payload.kind === 'ellipse' ? 'rounded-full' : ''"
                                    x-bind:style="`border:${Math.max(1, overlay.payload.stroke_width * scale)}px solid ${overlay.payload.stroke};background-color:${overlay.payload.fill || 'transparent'};opacity:${overlay.payload.opacity}`"
                                ></div>

                                {{-- image --}}
                                <img x-show="overlay.type === 'image'" x-bind:src="overlay.payload.data" class="h-full w-full" alt="" draggable="false" />

                                {{-- signature (an image placed from the pad/type/upload) --}}
                                <img x-show="overlay.type === 'signature'" x-bind:src="overlay.payload.data" class="h-full w-full" alt="" draggable="false" />

                                {{-- form field: free-text value --}}
                                <template x-if="overlay.type === 'form_field' && !isCheckboxField(overlay)">
                                    <input
                                        type="text"
                                        class="h-full w-full border-0 bg-transparent px-1 leading-tight outline-none"
                                        x-bind:style="`font-size:${(overlay.payload.font_size || 12) * scale}px;color:${overlay.payload.color};text-align:${overlay.payload.align}`"
                                        x-model="overlay.payload.value"
                                        x-bind:placeholder="overlay.payload.field_name"
                                        x-on:input="scheduleSave()"
                                        x-on:focus="selectedId = overlay.id"
                                        x-on:pointerdown.stop
                                    />
                                </template>

                                {{-- form field: checkbox / radio --}}
                                <template x-if="overlay.type === 'form_field' && isCheckboxField(overlay)">
                                    <button
                                        type="button"
                                        class="flex h-full w-full items-center justify-center font-bold leading-none"
                                        x-bind:style="`color:${overlay.payload.color}`"
                                        x-on:click.stop="toggleFormCheckbox(overlay)"
                                        x-on:pointerdown.stop="selectedId = overlay.id"
                                    >
                                        <span x-show="isTruthy(overlay.payload.value)">✕</span>
                                    </button>
                                </template>

                                {{-- text. The initial content is written ONCE via x-init; we must
                                     not bind it reactively (x-text), or every keystroke would rewrite
                                     the node and snap the caret to the start (typed text comes out
                                     reversed). x-effect only re-syncs the DOM for programmatic changes
                                     while the field is unfocused (e.g. undo/redo). --}}
                                <div
                                    x-show="overlay.type === 'text'"
                                    x-bind:data-text="overlay.id"
                                    contenteditable="true"
                                    spellcheck="false"
                                    class="h-full w-full overflow-visible whitespace-pre leading-tight outline-none"
                                    x-init="$el.innerText = overlay.payload.text || ''"
                                    x-effect="if (document.activeElement !== $el && $el.innerText !== (overlay.payload.text || '')) { $el.innerText = overlay.payload.text || ''; }"
                                    x-bind:style="`font-size:${overlay.payload.font_size * scale}px;color:${overlay.payload.color};text-align:${overlay.payload.align};font-family:${fontStack(overlay.payload.font)};font-weight:${overlay.payload.bold ? '700' : '400'};font-style:${overlay.payload.italic ? 'italic' : 'normal'};opacity:${overlay.payload.opacity}`"
                                    x-on:input="onTextInput(overlay, $event)"
                                    x-on:pointerdown.stop
                                ></div>

                                {{-- text move handle --}}
                                <button
                                    type="button"
                                    x-show="overlay.id === selectedId && overlay.type === 'text'"
                                    x-on:pointerdown.stop.prevent="startMove(overlay, $event)"
                                    class="absolute -left-3 -top-3 flex size-5 cursor-move items-center justify-center rounded-full bg-lapis-500 text-white shadow"
                                    title="{{ __('Move') }}"
                                >
                                    <flux:icon name="arrows-pointing-out" class="size-3" />
                                </button>

                                {{-- resize handle --}}
                                <div
                                    x-show="overlay.id === selectedId && canResize(overlay)"
                                    x-on:pointerdown.stop.prevent="startResize(overlay, $event)"
                                    class="absolute -bottom-1.5 -right-1.5 size-3 cursor-se-resize rounded-sm border border-white bg-lapis-500"
                                ></div>
                            </div>
                        </template>

                        {{-- Vector overlays (lines + freehand) + the live draft preview.
                             Each renders in its OWN absolutely-positioned, full-surface <svg>
                             so the <template> directives stay in HTML context — a <template>
                             nested inside an <svg> loses Alpine's x-for/x-if scope. --}}
                        <template x-for="overlay in lineOverlays" :key="overlay.id">
                            <svg class="pointer-events-none absolute left-0 top-0" x-bind:width="surfaceWidth" x-bind:height="surfaceHeight" fill="none">
                                <line
                                    x-bind:x1="lineToPoints(overlay.payload).x1"
                                    x-bind:y1="lineToPoints(overlay.payload).y1"
                                    x-bind:x2="lineToPoints(overlay.payload).x2"
                                    x-bind:y2="lineToPoints(overlay.payload).y2"
                                    x-bind:stroke="overlay.payload.stroke"
                                    x-bind:stroke-width="Math.max(1, overlay.payload.stroke_width * scale)"
                                    x-bind:opacity="overlay.payload.opacity"
                                    stroke-linecap="round"
                                    style="pointer-events: stroke; cursor: move"
                                    x-on:pointerdown="startMove(overlay, $event)"
                                />
                            </svg>
                        </template>

                        <template x-for="overlay in freehandOverlays" :key="overlay.id">
                            <svg class="pointer-events-none absolute left-0 top-0" x-bind:width="surfaceWidth" x-bind:height="surfaceHeight" fill="none">
                                <polyline
                                    x-bind:points="freehandPoints(overlay.payload)"
                                    x-bind:stroke="overlay.payload.stroke"
                                    x-bind:stroke-width="Math.max(1, overlay.payload.stroke_width * scale)"
                                    x-bind:opacity="overlay.payload.opacity"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    style="pointer-events: stroke; cursor: move"
                                    x-on:pointerdown="startMove(overlay, $event)"
                                />
                            </svg>
                        </template>

                        {{-- draft line preview --}}
                        <template x-if="draft && draft.preview && draft.preview.kind === 'line'">
                            <svg class="pointer-events-none absolute left-0 top-0" x-bind:width="surfaceWidth" x-bind:height="surfaceHeight" fill="none">
                                <line
                                    x-bind:x1="draft.preview.x1"
                                    x-bind:y1="draft.preview.y1"
                                    x-bind:x2="draft.preview.x2"
                                    x-bind:y2="draft.preview.y2"
                                    stroke="#3b82f6"
                                    stroke-width="2"
                                    stroke-dasharray="4 3"
                                />
                            </svg>
                        </template>

                        {{-- draft rect preview --}}
                        <template x-if="draft && draft.preview && draft.preview.kind === 'rect'">
                            <div
                                class="pointer-events-none absolute border-2 border-dashed border-lapis-500/70 bg-lapis-500/10"
                                x-bind:style="`left:${draft.preview.left}px;top:${draft.preview.top}px;width:${draft.preview.width}px;height:${draft.preview.height}px;`"
                            ></div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Properties panel --}}
            <div class="hidden w-60 shrink-0 overflow-y-auto border-s border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900 md:block">
                <div x-show="!selected" class="text-sm text-zinc-400">
                    {{ __('Select an edit to change its style, or pick a tool to add one.') }}
                </div>

                <div x-show="selected" x-cloak class="flex flex-col gap-4">
                    <flux:heading size="sm">{{ __('Properties') }}</flux:heading>

                    {{-- Color --}}
                    <label x-show="colorKey" class="flex items-center justify-between text-sm text-zinc-600 dark:text-zinc-300">
                        {{ __('Color') }}
                        <input type="color" class="h-7 w-10 cursor-pointer rounded border border-zinc-200 dark:border-zinc-800" x-bind:value="selected && colorKey ? (selected.payload[colorKey] || '#000000') : '#000000'" x-on:input="updatePayload(colorKey, $event.target.value)" />
                    </label>

                    {{-- Fill (shapes) --}}
                    <template x-if="selected && selected.type === 'shape'">
                        <div class="flex flex-col gap-2">
                            <label class="flex items-center justify-between text-sm text-zinc-600 dark:text-zinc-300">
                                {{ __('Filled') }}
                                <input type="checkbox" x-bind:checked="!!selected.payload.fill" x-on:change="updatePayload('fill', $event.target.checked ? '#fde047' : null)" />
                            </label>
                            <label x-show="selected.payload.fill" class="flex items-center justify-between text-sm text-zinc-600 dark:text-zinc-300">
                                {{ __('Fill color') }}
                                <input type="color" class="h-7 w-10 cursor-pointer rounded border border-zinc-200 dark:border-zinc-800" x-bind:value="selected.payload.fill || '#fde047'" x-on:input="updatePayload('fill', $event.target.value)" />
                            </label>
                        </div>
                    </template>

                    {{-- Font size + style (text) --}}
                    <template x-if="isText">
                        <div class="flex flex-col gap-2">
                            <label class="flex items-center justify-between text-sm text-zinc-600 dark:text-zinc-300">
                                {{ __('Font') }}
                                <select class="w-28 rounded border border-zinc-200 px-2 py-1 text-sm dark:border-zinc-800 dark:bg-zinc-800" x-bind:value="selected.payload.font || 'sans'" x-on:change="updatePayload('font', $event.target.value)">
                                    <option value="sans">{{ __('Sans-serif') }}</option>
                                    <option value="serif">{{ __('Serif') }}</option>
                                    <option value="mono">{{ __('Monospace') }}</option>
                                </select>
                            </label>
                            <label class="flex items-center justify-between text-sm text-zinc-600 dark:text-zinc-300">
                                {{ __('Font size') }}
                                <input type="number" min="6" max="96" class="w-20 rounded border border-zinc-200 px-2 py-1 text-sm dark:border-zinc-800 dark:bg-zinc-800" x-bind:value="selected.payload.font_size" x-on:change="updatePayload('font_size', Number($event.target.value))" />
                            </label>
                            <div class="flex items-center gap-1">
                                <flux:button size="sm" x-bind:variant="selected.payload.bold ? 'primary' : 'ghost'" x-on:click="updatePayload('bold', !selected.payload.bold)">B</flux:button>
                                <flux:button size="sm" x-bind:variant="selected.payload.italic ? 'primary' : 'ghost'" x-on:click="updatePayload('italic', !selected.payload.italic)"><span class="italic">I</span></flux:button>
                                <flux:button size="sm" variant="ghost" icon="bars-3-bottom-left" x-on:click="updatePayload('align', 'left')" />
                                <flux:button size="sm" variant="ghost" icon="bars-3" x-on:click="updatePayload('align', 'center')" />
                                <flux:button size="sm" variant="ghost" icon="bars-3-bottom-right" x-on:click="updatePayload('align', 'right')" />
                            </div>
                        </div>
                    </template>

                    {{-- Stroke width --}}
                    <label x-show="hasStrokeWidth" class="flex items-center justify-between text-sm text-zinc-600 dark:text-zinc-300">
                        {{ __('Thickness') }}
                        <input type="number" min="0.5" max="20" step="0.5" class="w-20 rounded border border-zinc-200 px-2 py-1 text-sm dark:border-zinc-800 dark:bg-zinc-800" x-bind:value="selected?.payload?.stroke_width" x-on:change="updatePayload('stroke_width', Number($event.target.value))" />
                    </label>

                    {{-- Opacity --}}
                    <label x-show="hasOpacity" class="flex flex-col gap-1 text-sm text-zinc-600 dark:text-zinc-300">
                        <span class="flex items-center justify-between">{{ __('Opacity') }} <span x-text="Math.round((selected?.payload?.opacity ?? 1) * 100) + '%'"></span></span>
                        <input type="range" min="0.1" max="1" step="0.05" x-bind:value="selected?.payload?.opacity ?? 1" x-on:input="updatePayload('opacity', Number($event.target.value))" />
                    </label>

                    <flux:separator />

                    <div class="flex flex-col gap-2">
                        <flux:button size="sm" variant="ghost" icon="arrow-up-on-square" x-on:click="bringToFront()">{{ __('Bring to front') }}</flux:button>
                        <flux:button size="sm" variant="danger" icon="trash" x-on:click="deleteSelected()">{{ __('Delete') }}</flux:button>
                    </div>
                </div>
            </div>
        </div>

        <input type="file" accept="image/png,image/jpeg" class="hidden" x-ref="image" x-on:change="onImageChosen($event)" />

        {{-- Signature modal (pure Alpine so it shares the editor's client state) --}}
        <div x-show="signatureOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-on:keydown.escape.window="closeSignature()">
            <div class="absolute inset-0 bg-black/40" x-on:click="closeSignature()"></div>

            <div class="relative z-10 flex max-h-[90vh] w-full max-w-lg flex-col gap-4 overflow-y-auto rounded-xl bg-white p-5 shadow-xl dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Add signature') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="closeSignature()" />
                </div>

                {{-- Tabs --}}
                <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                    @foreach (['draw' => __('Draw'), 'type' => __('Type'), 'upload' => __('Upload')] as $key => $label)
                        <button
                            type="button"
                            class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition"
                            x-bind:class="signatureTab === '{{ $key }}' ? 'bg-white text-zinc-900 shadow dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                            x-on:click="signatureTab = '{{ $key }}'; if ('{{ $key }}' === 'draw') $nextTick(() => resetPad())"
                        >{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Draw --}}
                <div x-show="signatureTab === 'draw'" class="flex flex-col gap-2">
                    <canvas
                        x-ref="pad"
                        class="h-40 w-full touch-none rounded-lg border border-zinc-300 bg-white dark:border-zinc-600"
                        x-on:pointerdown.prevent="padDown($event)"
                        x-on:pointermove="padMove($event)"
                        x-on:pointerup="padUp($event)"
                        x-on:pointerleave="padUp($event)"
                    ></canvas>
                    <div class="flex justify-end">
                        <flux:button size="sm" variant="ghost" icon="trash" x-on:click="clearPad()">{{ __('Clear') }}</flux:button>
                    </div>
                </div>

                {{-- Type --}}
                <div x-show="signatureTab === 'type'" x-cloak class="flex flex-col gap-3">
                    <input
                        type="text"
                        maxlength="60"
                        placeholder="{{ __('Type your name') }}"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        x-model="typeText"
                    />
                    <div class="flex flex-wrap gap-2">
                        <template x-for="font in signatureFonts" :key="font.label">
                            <button
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm"
                                x-bind:class="typeFont === font.stack ? 'border-lapis-500 bg-lapis-50 dark:bg-lapis-950' : 'border-zinc-300 dark:border-zinc-600'"
                                x-bind:style="`font-family:${font.stack}`"
                                x-on:click="typeFont = font.stack"
                                x-text="font.label"
                            ></button>
                        </template>
                    </div>
                    <div class="flex h-24 items-center justify-center rounded-lg border border-dashed border-zinc-300 px-3 text-3xl text-zinc-800 dark:border-zinc-600 dark:text-zinc-100" x-bind:style="`font-family:${typeFont}`">
                        <span x-text="typeText || '{{ __('Preview') }}'" x-bind:class="typeText ? '' : 'text-zinc-400'"></span>
                    </div>
                </div>

                {{-- Upload --}}
                <div x-show="signatureTab === 'upload'" x-cloak class="flex flex-col gap-3">
                    <input
                        type="file"
                        accept="image/png,image/jpeg"
                        class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm dark:text-zinc-300 dark:file:bg-zinc-800"
                        x-on:change="onSignatureUpload($event)"
                    />
                    <div x-show="uploadData" x-cloak class="flex h-24 items-center justify-center rounded-lg border border-dashed border-zinc-300 p-2 dark:border-zinc-600">
                        <img x-bind:src="uploadData" class="max-h-full" alt="" />
                    </div>
                </div>

                {{-- Name + actions --}}
                <div class="flex flex-col gap-2">
                    <input
                        type="text"
                        maxlength="60"
                        placeholder="{{ __('Name (optional, for reuse)') }}"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        x-model="signatureName"
                    />
                    <div class="flex items-center justify-end gap-2">
                        <flux:button size="sm" variant="ghost" icon="bookmark" x-on:click="saveSignatureForReuse()" x-bind:disabled="!canUseSignature">{{ __('Save for reuse') }}</flux:button>
                        <flux:button size="sm" variant="primary" icon="plus" x-on:click="addSignatureToPage()" x-bind:disabled="!canUseSignature">{{ __('Add to page') }}</flux:button>
                    </div>
                </div>

                {{-- Saved signatures --}}
                <div x-show="signatures.length" x-cloak class="flex flex-col gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-800">
                    <flux:text class="text-sm">{{ __('Saved signatures') }}</flux:text>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <template x-for="sig in signatures" :key="sig.id">
                            <div class="group relative flex h-16 items-center justify-center rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-800 dark:bg-zinc-800">
                                <button type="button" class="flex h-full w-full items-center justify-center" x-on:click="placeSaved(sig)" x-bind:title="sig.name">
                                    <img x-bind:src="sig.data" class="max-h-full max-w-full" alt="" />
                                </button>
                                <button type="button" class="absolute -right-1.5 -top-1.5 hidden size-5 items-center justify-center rounded-full bg-red-500 text-white shadow group-hover:flex" x-on:click.stop="removeSaved(sig)" title="{{ __('Delete') }}">
                                    <flux:icon name="x-mark" class="size-3" />
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
