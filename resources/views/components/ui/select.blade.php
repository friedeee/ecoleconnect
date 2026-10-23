@props(['label', 'name', 'hint' => null, 'placeholder' => null])

<x-ui.field :$label :$name :$hint :class="$attributes->get('class')">
    <select
        id="{{ $name }}"
        wire:model="{{ $name }}"
        {{ $attributes->except('class')->class([
            'block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-xs ring-1 ring-inset focus:ring-2 focus:ring-inset focus:outline-none',
            'ring-red-400 focus:ring-red-500' => $errors->has($name),
            'ring-zinc-300 focus:ring-brand-500' => ! $errors->has($name),
        ]) }}
    >
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        {{ $slot }}
    </select>
</x-ui.field>
