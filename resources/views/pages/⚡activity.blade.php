<?php

use App\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Journal d\'activité')] class extends Component
{
    use WithPagination;

    #[Url(as: 'type', except: '')]
    public string $category = '';

    #[Url(as: 'auteur', except: '')]
    public string $userId = '';

    #[Url(as: 'du', except: '')]
    public string $from = '';

    #[Url(as: 'au', except: '')]
    public string $to = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $openId = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['category', 'userId', 'from', 'to', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * People who appear in the school's log, to filter by author.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function authors(): Collection
    {
        return ActivityLog::query()
            ->whereNotNull('user_id')
            ->select('user_id', 'user_name')
            ->distinct()
            ->orderBy('user_name')
            ->pluck('user_name', 'user_id');
    }

    /**
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return ActivityLog::query()
            ->when(array_key_exists($this->category, ActivityLog::CATEGORIES), fn (Builder $query) => $query->where('action', 'like', $this->category.'.%'))
            ->when($this->userId !== '', fn (Builder $query) => $query->where('user_id', (int) $this->userId))
            ->when($this->from !== '' && strtotime($this->from), fn (Builder $query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '' && strtotime($this->to), fn (Builder $query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->search !== '', fn (Builder $query) => $query->whereLike('description', '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(30);
    }

    public function toggle(int $entryId): void
    {
        $this->openId = $this->openId === $entryId ? null : $entryId;
    }
};
?>

<div>
    <x-ui.page-header title="Journal d'activité" description="Qui a fait quoi, et quand. Le journal ne peut être ni modifié ni effacé depuis l'application." />

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Rechercher (élève, n° de reçu…)" aria-label="Rechercher" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 lg:col-span-2">
        <select wire:model.live="category" aria-label="Type d'action" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
            <option value="">Toutes les actions</option>
            @foreach (\App\Models\ActivityLog::CATEGORIES as $value => $label)
                <option value="{{ $value }}" wire:key="category-{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="userId" aria-label="Auteur" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
            <option value="">Tout le monde</option>
            @foreach ($this->authors as $id => $name)
                <option value="{{ $id }}" wire:key="author-{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <div class="flex items-center gap-2 text-sm text-zinc-600">
            <input type="date" wire:model.live="from" aria-label="Du" class="w-full rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm focus:border-brand-500 focus:outline-none">
            <span>→</span>
            <input type="date" wire:model.live="to" aria-label="Au" class="w-full rounded-lg border border-zinc-300 bg-white px-2 py-2 text-sm focus:border-brand-500 focus:outline-none">
        </div>
    </div>

    @if ($this->entries->isEmpty())
        <x-ui.empty icon="clock" title="Aucune activité">Rien ne correspond à ces filtres.</x-ui.empty>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white">
            <ul class="divide-y divide-zinc-100">
                @foreach ($this->entries as $entry)
                    @php($details = $entry->properties['changes'] ?? $entry->properties['marks'] ?? [])
                    <li wire:key="entry-{{ $entry->id }}" class="px-4 py-3 text-sm">
                        <div class="flex flex-wrap items-start gap-x-3 gap-y-1">
                            <span class="w-32 shrink-0 tabular-nums text-xs text-zinc-500">{{ $entry->created_at->format('d/m/Y H:i') }}</span>
                            <x-ui.badge>{{ $entry->category() }}</x-ui.badge>
                            <p class="min-w-0 flex-1 text-zinc-900">{{ $entry->description }}</p>
                            <span class="text-xs text-zinc-500">{{ $entry->user_name ?? 'Système' }}</span>
                        </div>
                        @if ($details !== [])
                            <button type="button" wire:click="toggle({{ $entry->id }})" class="mt-1 ml-0 text-xs font-semibold text-brand-700 hover:underline sm:ml-35">
                                {{ $openId === $entry->id ? 'Masquer le détail' : 'Voir le détail ('.count($details).')' }}
                            </button>
                            @if ($openId === $entry->id)
                                <table class="mt-2 w-full max-w-lg text-xs sm:ml-35">
                                    <thead class="text-left text-zinc-500">
                                        <tr><th class="py-1 font-medium"></th><th class="py-1 text-right font-medium">Avant</th><th class="py-1 text-right font-medium">Après</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100">
                                        @foreach ($details as $change)
                                            <tr wire:key="change-{{ $entry->id }}-{{ $loop->index }}">
                                                <td class="py-1 pr-3">{{ $change['student'] ?? '' }}</td>
                                                <td class="py-1 text-right tabular-nums text-zinc-500 line-through decoration-zinc-300">{{ $change['old'] }}</td>
                                                <td class="py-1 text-right font-semibold tabular-nums">{{ $change['new'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        <x-ui.pagination :paginator="$this->entries" noun="actions" class="mt-4" />
    @endif
</div>
