<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'PDF Studio') }} — Edit PDFs live in your browser</title>
        <meta name="description" content="Upload, organize, annotate, fill, sign, OCR, export to Word, and chat with your PDFs — without ever re-encoding the original file.">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        {{-- Top navigation --}}
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-5">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                <span class="flex aspect-square size-8 items-center justify-center rounded-md bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                    <x-app-logo-icon class="size-5 fill-current" />
                </span>
                {{ config('app.name', 'PDF Studio') }}
            </a>

            <nav class="flex items-center gap-2">
                @auth
                    <flux:button :href="route('dashboard')" wire:navigate variant="primary" size="sm">{{ __('Dashboard') }}</flux:button>
                @else
                    <flux:button :href="route('login')" wire:navigate variant="ghost" size="sm">{{ __('Log in') }}</flux:button>
                    @if (Route::has('register'))
                        <flux:button :href="route('register')" wire:navigate variant="primary" size="sm">{{ __('Get started') }}</flux:button>
                    @endif
                @endauth
            </nav>
        </header>

        {{-- Hero --}}
        <main class="mx-auto w-full max-w-6xl px-6">
            <section class="flex flex-col items-center gap-6 py-16 text-center sm:py-24">
                <flux:badge color="indigo" size="sm">{{ __('Portfolio project') }}</flux:badge>

                <h1 class="max-w-3xl text-4xl font-bold tracking-tight sm:text-6xl">
                    {{ __('Edit your PDFs live') }}
                    <span class="text-indigo-600 dark:text-indigo-400">{{ __('in the browser') }}</span>
                </h1>

                <p class="max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                    {{ __('Upload, organize, annotate, fill forms, sign, OCR, export to Word, and chat with your documents — without ever re-encoding the original file.') }}
                </p>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    @auth
                        <flux:button :href="route('documents.index')" wire:navigate variant="primary" icon="document-text">{{ __('Open your library') }}</flux:button>
                    @else
                        @if (Route::has('register'))
                            <flux:button :href="route('register')" wire:navigate variant="primary" icon="arrow-up-tray">{{ __('Upload your first PDF') }}</flux:button>
                        @endif
                        <flux:button :href="route('login')" wire:navigate variant="ghost">{{ __('I already have an account') }}</flux:button>
                    @endauth
                </div>

                <p class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-500">
                    <flux:icon name="lock-closed" class="size-4" />
                    {{ __('Non-destructive by default — your original file is never overwritten.') }}
                </p>
            </section>

            {{-- Feature grid --}}
            <section class="grid gap-4 pb-8 sm:grid-cols-2 lg:grid-cols-3">
                @php
                    $features = [
                        ['cursor-arrow-rays', __('Live overlay editing'), __('Add text, whiteout, highlights, shapes, images and freehand on top of the page — placement is zoom-independent.')],
                        ['rectangle-stack', __('Lossless page operations'), __('Reorder, rotate, delete, split and merge pages with 100% fidelity. Pages are copied, never re-rendered.')],
                        ['pencil-square', __('Forms & signatures'), __('Detect and fill AcroForm fields, then draw, type or upload a signature and flatten it onto the page.')],
                        ['document-arrow-down', __('Smart Word export'), __('Native pages convert layout-aware; scanned pages are OCR’d first — the edge over naive PDF→DOCX tools.')],
                        ['sparkles', __('AI assistant'), __('Chat with your PDF (grounded RAG with citations), summarize and translate — for native and scanned documents.')],
                        ['clock', __('Versioned & private'), __('Every bake is a new version; originals stay immutable. All documents are scoped to your account.')],
                    ];
                @endphp

                @foreach ($features as [$icon, $title, $body])
                    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-800 dark:bg-zinc-900/50">
                        <span class="flex size-10 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-400">
                            <flux:icon :name="$icon" class="size-5" />
                        </span>
                        <flux:heading size="lg">{{ $title }}</flux:heading>
                        <flux:text>{{ $body }}</flux:text>
                    </div>
                @endforeach
            </section>

            {{-- The fidelity story --}}
            <section class="my-12 grid gap-8 rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900 lg:grid-cols-2 lg:p-12">
                <div class="flex flex-col gap-4">
                    <flux:badge color="emerald" size="sm">{{ __('The Golden Rule') }}</flux:badge>
                    <flux:heading size="xl">{{ __('We never regenerate your PDF.') }}</flux:heading>
                    <flux:text>
                        {{ __('A PDF stores positioned glyphs, not a reflowable document. Re-encoding the whole file is what makes other editors corrupt fonts, lines and layout. Here, the original bytes are preserved and edits are stored as structured overlay operations — then baked onto a copy on demand, as a new version.') }}
                    </flux:text>
                </div>
                <ul class="flex flex-col justify-center gap-4">
                    @foreach ([
                        __('Original upload is immutable — never overwritten.'),
                        __('Edits live as JSON overlay ops in PDF user space.'),
                        __('Baking flattens overlays onto a copy → a new version.'),
                        __('Fonts, vector lines and layout stay pixel-perfect.'),
                    ] as $point)
                        <li class="flex items-start gap-3">
                            <flux:icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <flux:text>{{ $point }}</flux:text>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Tech stack --}}
            <section class="flex flex-col items-center gap-4 py-12 text-center">
                <flux:text class="uppercase tracking-wider">{{ __('Built with') }}</flux:text>
                <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm font-medium text-zinc-500 dark:text-zinc-400">
                    @foreach (['Laravel 13', 'Livewire 4', 'Flux UI', 'PDF.js', 'Python · FastAPI', 'PyMuPDF', 'Claude'] as $tech)
                        <span>{{ $tech }}</span>
                    @endforeach
                </div>
            </section>
        </main>

        {{-- Footer --}}
        <footer class="mx-auto w-full max-w-6xl border-t border-zinc-200 px-6 py-8 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-500">
            <div class="flex flex-col items-center justify-between gap-2 sm:flex-row">
                <span>&copy; {{ date('Y') }} {{ config('app.name', 'PDF Studio') }}. {{ __('A portfolio project.') }}</span>
                <span>{{ __('Laravel · Livewire · FastAPI · PyMuPDF') }}</span>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
