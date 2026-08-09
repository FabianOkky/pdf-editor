@props([
    'sidebar' => false,
])

@php
    $chip = 'flex aspect-square size-8 items-center justify-center rounded-lg bg-lapis-600 text-white shadow-sm dark:bg-lapis-500';
@endphp

@if($sidebar)
    <flux:sidebar.brand name="{{ config('app.name', 'Lapis') }}" {{ $attributes }}>
        <x-slot name="logo" :class="$chip">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="{{ config('app.name', 'Lapis') }}" {{ $attributes }}>
        <x-slot name="logo" :class="$chip">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
