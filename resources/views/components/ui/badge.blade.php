@props(['tone' => 'neutral'])

<span {{ $attributes->class([
    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
    'bg-zinc-50 text-zinc-700 ring-zinc-200' => $tone === 'neutral',
    'bg-brand-50 text-brand-800 ring-brand-200' => $tone === 'brand',
    'bg-amber-50 text-amber-800 ring-amber-200' => $tone === 'amber',
    'bg-sky-50 text-sky-800 ring-sky-200' => $tone === 'sky',
    'bg-violet-50 text-violet-800 ring-violet-200' => $tone === 'violet',
    'bg-red-50 text-red-700 ring-red-200' => $tone === 'red',
]) }}>{{ $slot }}</span>
