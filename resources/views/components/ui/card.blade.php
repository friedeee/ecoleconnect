@props(['title' => null, 'icon' => null, 'padded' => true])

<section {{ $attributes->class('card') }}>
    @if ($title || isset($actions))
        <header class="flex items-center gap-3 border-b border-zinc-100 px-5 py-4">
            @if ($icon)
                <span class="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-700"><x-ui.icon :name="$icon" class="size-4.5" /></span>
            @endif
            <h2 class="flex-1 text-sm font-semibold text-zinc-900">{{ $title }}</h2>
            {{ $actions ?? '' }}
        </header>
    @endif
    <div @class(['p-5' => $padded])>{{ $slot }}</div>
</section>
