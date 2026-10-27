<?php

use App\Models\ActivityLog;
use App\Enums\Role;
use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Écoles')] class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * @return Collection<int, School>
     */
    #[Computed]
    public function schools(): Collection
    {
        return School::query()
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', '%'.$this->search.'%')
                ->orWhereLike('city', '%'.$this->search.'%')))
            ->with(['users' => fn ($query) => $query->where('role', Role::Admin)->orderBy('id')])
            ->withCount('students')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function toggleActive(int $schoolId): void
    {
        Gate::authorize('manage-platform');

        $school = School::findOrFail($schoolId);
        $school->update(['is_active' => ! $school->is_active]);
        ActivityLog::record('platform.school-'.($school->is_active ? 'reactivated' : 'suspended'), 'École '.$school->name.' '.($school->is_active ? 'réactivée' : 'suspendue'), $school);
        unset($this->schools);
    }
};
?>

<div>
    <x-ui.page-header eyebrow="Plateforme" title="Écoles" :description="$this->schools->count().' école(s) cliente(s)'">
        <x-ui.button :href="route('platform.schools.create')" icon="plus">Ouvrir une école</x-ui.button>
    </x-ui.page-header>

    <div class="mb-4">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Rechercher par nom ou ville…"
            aria-label="Rechercher une école"
            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 sm:max-w-xs"
        >
    </div>

    @if ($this->schools->isEmpty())
        <x-ui.empty>Aucune école ne correspond à votre recherche.</x-ui.empty>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-4 py-3">École</th>
                        <th class="px-4 py-3">Cycles</th>
                        <th class="px-4 py-3 text-right">Élèves</th>
                        <th class="px-4 py-3">Administrateur</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($this->schools as $school)
                        @php($admin = $school->users->first())
                        <tr wire:key="school-{{ $school->id }}" @class(['text-zinc-400' => ! $school->is_active])>
                            <td class="px-4 py-3">
                                <p class="font-medium text-zinc-900">{{ $school->name }}</p>
                                <p class="text-xs text-zinc-500">{{ $school->city ?? '—' }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($school->cycles ?? [] as $cycle)
                                        <x-ui.badge :tone="$cycle->tone()" wire:key="school-{{ $school->id }}-{{ $cycle->value }}">{{ $cycle->label() }}</x-ui.badge>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $school->students_count }}</td>
                            <td class="px-4 py-3">
                                @if ($admin)
                                    <p>{{ $admin->name }}</p>
                                    <p class="text-xs text-zinc-500">{{ $admin->email }}</p>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($school->is_active)
                                    <x-ui.badge tone="brand">Active</x-ui.badge>
                                @else
                                    <x-ui.badge tone="red">Suspendue</x-ui.badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <button
                                    type="button"
                                    wire:click="toggleActive({{ $school->id }})"
                                    wire:confirm="{{ $school->is_active ? 'Suspendre' : 'Réactiver' }} l'école {{ $school->name }} ?{{ $school->is_active ? ' Plus personne de cette école ne pourra se connecter.' : '' }}"
                                    @class([
                                        'text-sm font-medium hover:underline',
                                        'text-red-600' => $school->is_active,
                                        'text-brand-700' => ! $school->is_active,
                                    ])
                                >{{ $school->is_active ? 'Suspendre' : 'Réactiver' }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
