@props(['tone' => 'amber'])

<div {{ $attributes->class([
    'flex gap-3 rounded-xl border px-4 py-3 text-sm',
    'border-amber-200 bg-amber-50 text-amber-900' => $tone === 'amber',
    'border-red-200 bg-red-50 text-red-800' => $tone === 'red',
]) }} role="alert">
    <x-ui.icon name="warning" class="mt-0.5 size-4.5" />
    <div>{{ $slot }}</div>
</div>
