<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Lapis') }} — {{ __('Edit PDFs without breaking them') }}</title>
        <meta name="description" content="{{ __('Lapis edits PDFs as layers over the original bytes, so fonts, lines and layout survive. OCR, smart Word export and a grounded AI assistant included.') }}">
        <meta name="theme-color" content="#3a45dd">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        {{-- Top navigation --}}
        <header class="sticky top-0 z-30 border-b border-zinc-200/70 bg-zinc-50/80 backdrop-blur-md dark:border-zinc-800/70 dark:bg-zinc-950/80">
            <div class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-4">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                    <span class="flex aspect-square size-8 items-center justify-center rounded-lg bg-lapis-600 text-white shadow-sm dark:bg-lapis-500">
                        <x-app-logo-icon class="size-5" />
                    </span>
                    <span class="text-[15px] font-semibold tracking-tight">{{ config('app.name', 'Lapis') }}</span>
                </a>

                <nav class="flex items-center gap-1">
                    @auth
                        <flux:button :href="route('dashboard')" wire:navigate variant="primary" size="sm">{{ __('Open app') }}</flux:button>
                    @else
                        <flux:button :href="route('login')" wire:navigate variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
                        @if (Route::has('register'))
                            <flux:button :href="route('register')" wire:navigate variant="primary" size="sm">{{ __('Get started') }}</flux:button>
                        @endif
                    @endauth
                </nav>
            </div>
        </header>

        <main>
            {{-- Hero: asymmetric, with the product's own promise stated as a mechanism, not a slogan --}}
            <section class="relative overflow-hidden border-b border-zinc-200 dark:border-zinc-800">
                <div class="bg-grid pointer-events-none absolute inset-0 opacity-40 [mask-image:radial-gradient(70%_60%_at_50%_0%,black,transparent)] dark:opacity-25"></div>

                <div class="relative mx-auto grid w-full max-w-6xl gap-12 px-6 py-16 lg:grid-cols-12 lg:items-center lg:gap-8 lg:py-24">
                    <div class="flex flex-col items-start gap-6 lg:col-span-6">
                        <span class="inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                            {{ __('Non-destructive by design') }}
                        </span>

                        <h1 class="text-balance-heading text-4xl font-semibold leading-[1.05] tracking-tight sm:text-5xl lg:text-[3.4rem]">
                            {{ __('Edit PDFs') }}<br>
                            {{ __('without breaking') }}<br>
                            <span class="text-lapis-600 dark:text-lapis-400">{{ __('them.') }}</span>
                        </h1>

                        <p class="max-w-lg text-lg leading-relaxed text-zinc-600 dark:text-zinc-400">
                            {{ __('Most editors rewrite your whole file and quietly wreck the fonts and layout. Lapis keeps the original bytes untouched and stores your changes as a layer on top — then flattens them onto a copy when you say so.') }}
                        </p>

                        <div class="flex flex-wrap items-center gap-3 pt-1">
                            @auth
                                <flux:button :href="route('documents.index')" wire:navigate variant="primary" icon="folder-open">{{ __('Open your library') }}</flux:button>
                            @else
                                @if (Route::has('register'))
                                    <flux:button :href="route('register')" wire:navigate variant="primary" icon="arrow-up-tray">{{ __('Upload your first PDF') }}</flux:button>
                                @endif
                                <flux:button :href="route('login')" wire:navigate variant="ghost">{{ __('I already have an account') }}</flux:button>
                            @endauth
                        </div>

                        <dl class="mt-4 grid w-full max-w-md grid-cols-3 gap-px overflow-hidden rounded-xl border border-zinc-200 bg-zinc-200 text-center dark:border-zinc-800 dark:bg-zinc-800">
                            @foreach ([
                                ['100%', __('page fidelity')],
                                ['0', __('bytes rewritten')],
                                [__('Every'), __('edit versioned')],
                            ] as [$value, $label])
                                <div class="bg-zinc-50 px-3 py-4 dark:bg-zinc-950">
                                    <dt class="text-lg font-semibold tracking-tight">{{ $value }}</dt>
                                    <dd class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-500">{{ $label }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>

                    {{-- A drawn stand-in for the editor: honest about being an illustration, but it
                         shows the actual mental model (page + overlay layer + version history). --}}
                    <div class="lg:col-span-6">
                        <div class="relative mx-auto w-full max-w-lg">
                            <div class="absolute -inset-6 rounded-[2rem] bg-lapis-600/10 blur-2xl dark:bg-lapis-500/10"></div>

                            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl shadow-zinc-900/10 dark:border-zinc-800 dark:bg-zinc-900 dark:shadow-black/40">
                                <div class="flex items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                                    <div class="flex gap-1.5">
                                        <span class="size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-700"></span>
                                        <span class="size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-700"></span>
                                        <span class="size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-700"></span>
                                    </div>
                                    <span class="ms-2 truncate text-xs text-zinc-500 dark:text-zinc-500">quarterly-report.pdf</span>
                                    <span class="ms-auto rounded-md bg-lapis-50 px-2 py-0.5 text-[10px] font-medium text-lapis-700 dark:bg-lapis-950 dark:text-lapis-300">v3</span>
                                </div>

                                <div class="bg-stage p-6">
                                    <div class="relative mx-auto aspect-[3/4] w-full max-w-xs rounded-sm bg-white p-6 shadow-lg dark:bg-zinc-100">
                                        <div class="h-2.5 w-2/3 rounded-sm bg-zinc-800/80"></div>
                                        <div class="mt-2 h-1.5 w-1/3 rounded-sm bg-zinc-400/70"></div>
                                        <div class="mt-6 space-y-2">
                                            @foreach ([100, 92, 96, 70, 88, 94, 60] as $width)
                                                <div class="h-1.5 rounded-sm bg-zinc-300/80" style="width: {{ $width }}%"></div>
                                            @endforeach
                                        </div>

                                        {{-- The overlay layer, drawn as it behaves: sitting above the page --}}
                                        <div class="absolute left-6 top-[42%] h-4 w-24 rounded-sm bg-amber-300/60 ring-1 ring-amber-400/70"></div>
                                        <div class="absolute right-5 top-[58%] rounded-md border-2 border-dashed border-lapis-500 bg-lapis-500/10 px-2 py-1 text-[9px] font-medium text-lapis-700">
                                            {{ __('Approved') }}
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 text-xs dark:border-zinc-800">
                                    <span class="flex items-center gap-1.5 text-zinc-500 dark:text-zinc-500">
                                        <flux:icon name="lock-closed" class="size-3.5" />
                                        {{ __('Original preserved') }}
                                    </span>
                                    <span class="rounded-md bg-emerald-50 px-2 py-1 font-medium text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400">
                                        {{ __('2 overlay edits') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- How it works: the mechanism, in three beats --}}
            <section class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900/40">
                <div class="mx-auto w-full max-w-6xl px-6 py-16 lg:py-20">
                    <div class="max-w-2xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-lapis-600 dark:text-lapis-400">{{ __('How it works') }}</p>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Three steps, and the original never moves.') }}</h2>
                    </div>

                    <ol class="mt-12 grid gap-px overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-200 dark:border-zinc-800 dark:bg-zinc-800 md:grid-cols-3">
                        @foreach ([
                            [__('Upload'), __('Your file lands on private storage and is analysed page by page — native text, scanned, or a mix. The bytes are then left alone, permanently.')],
                            [__('Edit as a layer'), __('Text, whiteout, highlights, shapes, freehand, form fields and signatures are stored as structured operations in the page’s own coordinate space — not painted into the file.')],
                            [__('Flatten on demand'), __('When you are happy, the layer is stamped onto a copy and saved as a new version. Fonts, vector lines and layout come through untouched.')],
                        ] as $index => [$title, $body])
                            <li class="bg-white p-7 dark:bg-zinc-950">
                                <span class="flex size-8 items-center justify-center rounded-lg bg-lapis-600 text-sm font-semibold text-white dark:bg-lapis-500">{{ $index + 1 }}</span>
                                <h3 class="mt-5 text-lg font-semibold tracking-tight">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $body }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            {{-- Capabilities --}}
            <section class="border-b border-zinc-200 dark:border-zinc-800">
                <div class="mx-auto w-full max-w-6xl px-6 py-16 lg:py-20">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div class="max-w-2xl">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-lapis-600 dark:text-lapis-400">{{ __('What’s inside') }}</p>
                            <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('A full editing desk, not a one-trick converter.') }}</h2>
                        </div>
                    </div>

                    @php
                        $features = [
                            ['cursor-arrow-rays', __('Live overlay editing'), __('Text, whiteout, highlights, shapes, images and freehand, placed in PDF user space so they stay put at any zoom.')],
                            ['rectangle-stack', __('Lossless page operations'), __('Reorder, rotate, delete, split and merge. Pages are copied, never re-rendered.')],
                            ['pencil-square', __('Forms & signatures'), __('Detect and fill AcroForm fields, then draw, type or upload a signature and flatten it onto the page.')],
                            ['document-arrow-down', __('Smart Word export'), __('Native pages convert layout-aware; scanned pages are OCR’d first, and mixed files get both — page by page.')],
                            ['sparkles', __('Grounded AI assistant'), __('Chat with a document, summarize or translate it. Answers cite the pages they came from, and never touch the PDF.')],
                            ['clock', __('Versioned & private'), __('Every flatten is a new version you can restore. Documents are scoped to your account on private storage.')],
                        ];
                    @endphp

                    <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($features as [$icon, $title, $body])
                            <div class="group rounded-xl border border-zinc-200 bg-white p-6 transition hover:border-lapis-300 hover:shadow-lg hover:shadow-lapis-900/5 dark:border-zinc-800 dark:bg-zinc-900/50 dark:hover:border-lapis-800">
                                <span class="flex size-10 items-center justify-center rounded-lg bg-lapis-50 text-lapis-600 transition group-hover:bg-lapis-600 group-hover:text-white dark:bg-lapis-950 dark:text-lapis-400 dark:group-hover:bg-lapis-500 dark:group-hover:text-white">
                                    <flux:icon :name="$icon" class="size-5" />
                                </span>
                                <h3 class="mt-5 font-semibold tracking-tight">{{ $title }}</h3>
                                <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $body }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- The fidelity story, as a comparison rather than a claim --}}
            <section class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900/40">
                <div class="mx-auto grid w-full max-w-6xl gap-12 px-6 py-16 lg:grid-cols-2 lg:items-center lg:py-20">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-600 dark:text-emerald-400">{{ __('The rule everything follows') }}</p>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('We never regenerate your PDF.') }}</h2>
                        <p class="mt-5 text-base leading-relaxed text-zinc-600 dark:text-zinc-400">
                            {{ __('A PDF is not a document — it is a set of glyphs pinned to coordinates, with subsetted fonts to match. Re-encoding the whole thing is what makes other editors lose a typeface, shift a table, or thicken every rule by a hair. Skipping that step is the entire design.') }}
                        </p>
                    </div>

                    <div class="grid gap-3">
                        @foreach ([
                            [true, __('Original upload is immutable'), __('It is streamed straight off disk, byte for byte, forever.')],
                            [true, __('Edits are structured operations'), __('Stored as JSON in the page’s own coordinate space.')],
                            [true, __('Flattening writes a copy'), __('The result is a new version; the history is append-only.')],
                            [false, __('No full re-encode. Ever.'), __('No font substitution, no reflow, no silent layout drift.')],
                        ] as [$positive, $title, $body])
                            <div class="flex items-start gap-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950">
                                <flux:icon
                                    :name="$positive ? 'check-circle' : 'x-circle'"
                                    @class([
                                        'mt-0.5 size-5 shrink-0',
                                        'text-emerald-600 dark:text-emerald-400' => $positive,
                                        'text-zinc-400 dark:text-zinc-600' => ! $positive,
                                    ])
                                />
                                <div>
                                    <div class="text-sm font-semibold tracking-tight">{{ $title }}</div>
                                    <div class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400">{{ $body }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Closing call to action --}}
            <section class="mx-auto w-full max-w-6xl px-6 py-20">
                <div class="relative overflow-hidden rounded-3xl bg-lapis-700 px-8 py-14 text-center dark:bg-lapis-800 sm:px-16">
                    <div class="bg-grid pointer-events-none absolute inset-0 opacity-10"></div>
                    <div class="relative flex flex-col items-center gap-6">
                        <h2 class="max-w-xl text-balance-heading text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                            {{ __('Bring a PDF you care about.') }}
                        </h2>
                        <p class="max-w-lg text-base text-lapis-100">
                            {{ __('Edit it, export it to Word, ask questions about it — and then download the original and diff it. It will be identical.') }}
                        </p>
                        {{-- A plain white button: Flux's variants are all tuned for neutral
                             surfaces and go muddy on the lapis panel. --}}
                        @auth
                            <a href="{{ route('documents.index') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-lapis-800 shadow-sm transition hover:bg-lapis-50">
                                <flux:icon name="folder-open" class="size-4" />
                                {{ __('Open your library') }}
                            </a>
                        @elseif (Route::has('register'))
                            <a href="{{ route('register') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-lapis-800 shadow-sm transition hover:bg-lapis-50">
                                <flux:icon name="arrow-up-tray" class="size-4" />
                                {{ __('Create a free account') }}
                            </a>
                        @endauth
                    </div>
                </div>
            </section>
        </main>

        {{-- Footer --}}
        <footer class="border-t border-zinc-200 dark:border-zinc-800">
            <div class="mx-auto flex w-full max-w-6xl flex-col items-center justify-between gap-4 px-6 py-8 text-sm text-zinc-500 dark:text-zinc-500 sm:flex-row">
                <div class="flex items-center gap-2.5">
                    <span class="flex aspect-square size-6 items-center justify-center rounded-md bg-lapis-600 text-white dark:bg-lapis-500">
                        <x-app-logo-icon class="size-3.5" />
                    </span>
                    <span>&copy; {{ date('Y') }} {{ config('app.name', 'Lapis') }}. {{ __('A portfolio project.') }}</span>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
                    @foreach (['Laravel 13', 'Livewire 4', 'PDF.js', 'FastAPI', 'PyMuPDF'] as $tech)
                        <span>{{ $tech }}</span>
                    @endforeach
                </div>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
