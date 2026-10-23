@props(['paginator', 'noun' => 'résultats', 'perPageOptions' => []])

@php
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator */
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();

    // First page, last page and the current one with its neighbours; null marks a gap.
    $pages = collect([1, $last, ...range(max(1, $current - 1), min($last, $current + 1))])
        ->unique()
        ->sort()
        ->values()
        ->reduce(function (array $carry, int $page): array {
            $previous = end($carry);

            if ($previous !== false && $page - $previous === 2) {
                $carry[] = $page - 1;
            } elseif ($previous !== false && $page - $previous > 2) {
                $carry[] = null;
            }

            $carry[] = $page;

            return $carry;
        }, []);

    $arrowClasses = 'inline-flex size-9 items-center justify-center rounded-lg text-zinc-600 transition hover:bg-zinc-100 hover:text-zinc-900 disabled:pointer-events-none disabled:opacity-40';
@endphp

<nav {{ $attributes->class('flex flex-col items-center justify-between gap-3 text-sm sm:flex-row') }} aria-label="Pagination">
    <p class="text-zinc-600">
        @if ($paginator->total() > 0)
            <span class="font-semibold text-zinc-900">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
            sur <span class="font-semibold text-zinc-900">{{ $paginator->total() }}</span> {{ $noun }}
        @endif
    </p>

    <div class="flex items-center gap-4">
        @if ($perPageOptions !== [])
            <label class="flex items-center gap-2 text-zinc-600">
                <span class="hidden sm:inline">Par page</span>
                <select
                    wire:model.live="perPage"
                    class="rounded-lg border-0 bg-white py-1.5 pr-8 pl-2.5 text-sm text-zinc-900 shadow-xs ring-1 ring-zinc-300 ring-inset focus:ring-2 focus:ring-brand-500 focus:outline-none"
                >
                    @foreach ($perPageOptions as $option)
                        <option value="{{ $option }}" wire:key="per-page-{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($paginator->hasPages())
            <div class="flex items-center gap-1">
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled" @disabled($paginator->onFirstPage()) class="{{ $arrowClasses }}" aria-label="Page précédente">
                    <x-ui.icon name="chevron-right" class="size-4 rotate-180" />
                </button>

                @foreach ($pages as $page)
                    @if ($page === null)
                        <span class="inline-flex size-9 items-center justify-center text-zinc-400" aria-hidden="true">…</span>
                    @elseif ($page === $current)
                        <span class="inline-flex size-9 items-center justify-center rounded-lg bg-brand-600 font-semibold text-white shadow-sm shadow-brand-900/20" aria-current="page">{{ $page }}</span>
                    @else
                        <button type="button" wire:click="gotoPage({{ $page }})" wire:key="page-{{ $page }}" class="inline-flex size-9 items-center justify-center rounded-lg font-medium text-zinc-700 transition hover:bg-zinc-100 hover:text-zinc-900" aria-label="Page {{ $page }}">{{ $page }}</button>
                    @endif
                @endforeach

                <button type="button" wire:click="nextPage" wire:loading.attr="disabled" @disabled(! $paginator->hasMorePages()) class="{{ $arrowClasses }}" aria-label="Page suivante">
                    <x-ui.icon name="chevron-right" class="size-4" />
                </button>
            </div>
        @endif
    </div>
</nav>
