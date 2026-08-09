<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">
        <div class="relative grid min-h-dvh lg:grid-cols-2">
            {{-- Brand panel: states the product's promise instead of a random inspirational quote. --}}
            <div class="relative hidden flex-col justify-between overflow-hidden bg-lapis-700 p-10 text-white lg:flex dark:bg-lapis-900">
                <div class="bg-grid pointer-events-none absolute inset-0 opacity-10"></div>

                <a href="{{ route('home') }}" class="relative z-10 flex items-center gap-2.5 font-semibold" wire:navigate>
                    <span class="flex aspect-square size-8 items-center justify-center rounded-lg bg-white/15">
                        <x-app-logo-icon class="size-5" />
                    </span>
                    {{ config('app.name', 'Lapis') }}
                </a>

                <div class="relative z-10 max-w-md">
                    <h2 class="text-3xl font-semibold leading-tight tracking-tight">
                        {{ __('Edit PDFs without breaking them.') }}
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-lapis-100">
                        {{ __('Your original file is never rewritten. Edits live as a layer on top and are flattened onto a copy only when you ask — so fonts, lines and layout survive intact.') }}
                    </p>

                    <ul class="mt-8 space-y-3">
                        @foreach ([
                            __('Live overlay editing, forms and signatures'),
                            __('OCR and smart PDF → Word export'),
                            __('An AI assistant that cites its pages'),
                        ] as $point)
                            <li class="flex items-center gap-3 text-sm text-lapis-100">
                                <flux:icon name="check-circle" class="size-4 shrink-0 text-white" />
                                {{ $point }}
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="relative z-10 text-xs text-lapis-200">
                    &copy; {{ date('Y') }} {{ config('app.name', 'Lapis') }}
                </p>
            </div>

            {{-- Form panel --}}
            <div class="flex w-full items-center justify-center px-6 py-12">
                <div class="flex w-full max-w-sm flex-col gap-8">
                    <a href="{{ route('home') }}" class="flex items-center justify-center gap-2.5 font-semibold lg:hidden" wire:navigate>
                        <span class="flex aspect-square size-8 items-center justify-center rounded-lg bg-lapis-600 text-white dark:bg-lapis-500">
                            <x-app-logo-icon class="size-5" />
                        </span>
                        {{ config('app.name', 'Lapis') }}
                    </a>

                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
