<?php

use App\Models\ActivityLog;
use App\Models\Absence;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public Enrollment $enrollment;

    public ?int $justifyingId = null;

    public string $reason = '';

    public function mount(Enrollment $enrollment): void
    {
        $this->enrollment = $enrollment->load('academicYear');
    }

    /**
     * The student's absences and lateness this school year, latest first.
     *
     * @return Collection<int, Absence>
     */
    #[Computed]
    public function absences(): Collection
    {
        return Absence::with('justifier')
            ->where('student_id', $this->enrollment->student_id)
            ->whereBetween('date', [$this->enrollment->academicYear->starts_on->toDateString(), $this->enrollment->academicYear->ends_on->toDateString()])
            ->latest('date')
            ->orderByDesc('session')
            ->get();
    }

    public function startJustifying(int $absenceId): void
    {
        Gate::authorize('justify-absences');

        $absence = $this->absences->firstWhere('id', $absenceId) ?? abort(404);

        $this->justifyingId = $absence->id;
        $this->reason = $absence->reason ?? '';
        $this->resetValidation();
    }

    public function justify(): void
    {
        Gate::authorize('justify-absences');

        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], attributes: ['reason' => 'motif']);

        $absence = $this->absences->firstWhere('id', $this->justifyingId) ?? abort(404);
        $absence->fill(['is_justified' => true, 'reason' => trim($this->reason)]);
        $absence->forceFill(['justified_by' => auth()->id()])->save();

        ActivityLog::record('attendance.justified', 'Absence de '.$absence->student->full_name.' du '.$absence->date->format('d/m/Y').' justifiée : '.$absence->reason, $absence);

        $this->reset(['justifyingId', 'reason']);
        unset($this->absences);
    }

    public function unjustify(int $absenceId): void
    {
        Gate::authorize('justify-absences');

        $absence = $this->absences->firstWhere('id', $absenceId) ?? abort(404);
        $absence->fill(['is_justified' => false, 'reason' => null]);
        $absence->forceFill(['justified_by' => null])->save();

        ActivityLog::record('attendance.unjustified', 'Justification retirée : absence de '.$absence->student->full_name.' du '.$absence->date->format('d/m/Y'), $absence);

        unset($this->absences);
    }
};
?>

@php
    $absent = $this->absences->where('status', \App\Enums\AttendanceStatus::Absent);
    $justified = $absent->where('is_justified', true)->count();
@endphp

<x-ui.card title="Absences {{ $enrollment->academicYear->name }}" icon="clock" class="lg:col-span-3">
    <div class="grid grid-cols-3 gap-4">
        <div>
            <p class="text-xs text-zinc-500">Absences (demi-journées)</p>
            <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ $absent->count() }}</p>
        </div>
        <div>
            <p class="text-xs text-zinc-500">Non justifiées</p>
            <p @class(['mt-0.5 text-lg font-semibold tabular-nums', 'text-red-600' => $absent->count() - $justified > 0])>{{ $absent->count() - $justified }}</p>
        </div>
        <div>
            <p class="text-xs text-zinc-500">Retards</p>
            <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ $this->absences->where('status', \App\Enums\AttendanceStatus::Late)->count() }}</p>
        </div>
    </div>

    @if ($this->absences->isNotEmpty())
        <ul class="mt-5 divide-y divide-zinc-100 text-sm">
            @foreach ($this->absences as $absence)
                <li wire:key="absence-{{ $absence->id }}" class="py-2.5">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-medium">{{ \Illuminate\Support\Str::ucfirst($absence->date->translatedFormat('l j F')) }}</span>
                        <span class="text-zinc-500">{{ mb_strtolower($absence->session->label()) }}</span>
                        <x-ui.badge :tone="$absence->status->tone()">{{ $absence->status->label() }}</x-ui.badge>
                        @if ($absence->is_justified)
                            <x-ui.badge tone="brand">Justifiée</x-ui.badge>
                            <span class="text-xs text-zinc-500">{{ $absence->reason }}</span>
                        @endif
                        @can('justify-absences')
                            <span class="ml-auto">
                                @if ($absence->is_justified)
                                    <button type="button" wire:click="unjustify({{ $absence->id }})" class="text-sm font-medium text-zinc-500 hover:underline">Retirer la justification</button>
                                @elseif ($justifyingId !== $absence->id)
                                    <button type="button" wire:click="startJustifying({{ $absence->id }})" class="text-sm font-medium text-brand-700 hover:underline">Justifier</button>
                                @endif
                            </span>
                        @endcan
                    </div>
                    @if ($justifyingId === $absence->id)
                        <form wire:submit="justify" class="mt-3 flex flex-col gap-2 rounded-lg bg-zinc-50 p-3 sm:flex-row sm:items-start">
                            <x-ui.input label="Motif" name="reason" placeholder="ex. Maladie (certificat médical), décès dans la famille…" class="flex-1" />
                            <div class="flex gap-2 sm:pt-7">
                                <x-ui.button type="button" variant="secondary" wire:click="$set('justifyingId', null)">Annuler</x-ui.button>
                                <x-ui.button>Justifier</x-ui.button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-4 text-sm text-zinc-500">Aucune absence ni retard cette année.</p>
    @endif
</x-ui.card>
