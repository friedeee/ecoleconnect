@props(['name', 'size' => 'md'])

@php
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
    $tones = ['bg-brand-100 text-brand-800', 'bg-sky-100 text-sky-800', 'bg-amber-100 text-amber-800', 'bg-violet-100 text-violet-800', 'bg-rose-100 text-rose-800', 'bg-teal-100 text-teal-800'];
    $tone = $tones[crc32($name) % count($tones)];
@endphp

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center rounded-full font-semibold uppercase',
    $tone,
    'size-8 text-xs' => $size === 'sm',
    'size-10 text-sm' => $size === 'md',
    'size-16 text-xl' => $size === 'lg',
]) }} aria-hidden="true">{{ $initials }}</span>
