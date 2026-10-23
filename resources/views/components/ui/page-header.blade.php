@props(['title', 'description' => null, 'eyebrow' => null])

<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand-600">{{ $eyebrow }}</p>
        @endif
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-950 sm:text-3xl">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1.5 text-sm text-zinc-500">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex shrink-0 flex-wrap gap-2">{{ $slot }}</div>
    @endif
</div>
