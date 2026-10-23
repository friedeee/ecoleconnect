@props(['variant' => 'primary', 'href' => null, 'icon' => null])

@php
    $classes = 'inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 disabled:opacity-50 '.match ($variant) {
        'primary' => 'bg-brand-600 text-white shadow-sm shadow-brand-900/20 hover:bg-brand-700',
        'secondary' => 'bg-white text-zinc-700 ring-1 ring-inset ring-zinc-300 shadow-xs hover:bg-zinc-50',
        'danger' => 'bg-red-600 text-white hover:bg-red-700',
        'ghost' => 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4.5" />@endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'submit'])->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" class="size-4.5" />@endif
        {{ $slot }}
    </button>
@endif
