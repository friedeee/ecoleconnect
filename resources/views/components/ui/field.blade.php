@props(['label', 'name', 'hint' => null])

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ $label }}</label>
    {{ $slot }}
    @if ($hint)
        <p class="mt-1.5 text-xs text-zinc-500">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
