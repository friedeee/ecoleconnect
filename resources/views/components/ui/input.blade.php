@props(['label', 'name', 'hint' => null, 'type' => 'text', 'icon' => null])

@php
    $classes = [
        'block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-xs ring-1 ring-inset placeholder:text-zinc-400 focus:ring-2 focus:ring-inset focus:outline-none',
        'ring-red-400 focus:ring-red-500' => $errors->has($name),
        'ring-zinc-300 focus:ring-brand-500' => ! $errors->has($name),
        // Room for the leading icon and for the show/hide button.
        'pl-10' => $icon !== null,
        'pr-11' => $type === 'password',
    ];
@endphp

<x-ui.field :$label :$name :$hint :class="$attributes->get('class')">
    <div class="relative" @if ($type === 'password') x-data="{ visible: false }" @endif>
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-zinc-400">
                <x-ui.icon :name="$icon" class="size-4.5" />
            </span>
        @endif

        <input
            id="{{ $name }}"
            type="{{ $type }}"
            @if ($type === 'password') x-bind:type="visible ? 'text' : 'password'" @endif
            wire:model="{{ $name }}"
            {{ $attributes->except('class')->class($classes) }}
        >

        @if ($type === 'password')
            <button
                type="button"
                x-on:click="visible = ! visible; $nextTick(() => $el.previousElementSibling.focus())"
                class="absolute inset-y-0 right-0 flex items-center rounded-r-xl px-3 text-zinc-400 transition hover:text-zinc-700 focus-visible:text-brand-600 focus-visible:outline-none"
                aria-controls="{{ $name }}"
                x-bind:aria-pressed="visible"
                x-bind:aria-label="visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                x-bind:title="visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                aria-label="Afficher le mot de passe"
            >
                {{-- Crossed eye while the password is hidden, open eye once it is shown. --}}
                <x-ui.icon name="eye-slash" class="size-5" x-show="! visible" />
                <x-ui.icon name="eye" class="size-5" x-show="visible" x-cloak />
            </button>
        @endif
    </div>
</x-ui.field>
