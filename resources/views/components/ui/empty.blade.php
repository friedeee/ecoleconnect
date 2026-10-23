@props(['icon' => 'search', 'title' => null])

<div class="card flex flex-col items-center px-6 py-14 text-center">
    <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><x-ui.icon :name="$icon" class="size-6" /></span>
    @if ($title)
        <p class="mt-4 font-semibold text-zinc-900">{{ $title }}</p>
    @endif
    <div class="mt-1 max-w-sm text-sm text-zinc-500">{{ $slot }}</div>
</div>
