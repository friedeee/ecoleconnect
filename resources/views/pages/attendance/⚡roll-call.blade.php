<?php

use App\Enums\AttendanceStatus;
use App\Enums\DaySession;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\RollCall;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /** Days a teacher may go back to correct a roll call; admins have no limit. */
    public const TEACHER_EDIT_DAYS = 7;

    #[Locked]
    public SchoolClass $schoolClass;

    #[Url(as: 'date')]
    public string $date = '';

    #[Url(as: 'seance')]
    public string $session = '';

    /**
     * Status of each student, keyed by student id.
     *
     * @var array<int, string>
     */
    public array $statuses = [];

    public function mount(SchoolClass $schoolClass): void
    {
        Gate::authorize('take-attendance', $schoolClass);

        $this->schoolClass = $schoolClass;
        $this->date = $this->day->format('Y-m-d');
        $this->session = $this->daySession->value;
        $this->loadStatuses();
    }

    #[Computed]
    public function day(): Carbon
    {
        $day = rescue(fn () => Carbon::parse($this->date), today(), report: false)->startOfDay();

        return $day->isAfter(today()) ? today() : $day;
    }

    #[Computed]
    public function daySession(): DaySession
    {
        return DaySession::tryFrom($this->session) ?? DaySession::now();
    }

    /**
     * Teachers only correct recent roll calls; admins can fix any day.
     */
    #[Computed]
    public function editable(): bool
    {
        return ! auth()->user()->hasRole(Role::Teacher) || $this->day->gte(today()->subDays(self::TEACHER_EDIT_DAYS));
    }

    /**
     * @return Collection<int, Student>
     */
    #[Computed]
    public function students(): Collection
    {
        return $this->schoolClass->students()->orderBy('last_name')->orderBy('first_name')->get();
    }

    #[Computed]
    public function rollCall(): ?RollCall
    {
        return RollCall::with('taker')
            ->whereBelongsTo($this->schoolClass)
            ->whereDate('date', $this->day)
            ->where('session', $this->daySession)
            ->first();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['date', 'session'], true)) {
            unset($this->day, $this->daySession, $this->rollCall, $this->editable);
            $this->loadStatuses();
        }
    }

    protected function loadStatuses(): void
    {
        $recorded = Absence::whereBelongsTo($this->schoolClass)
            ->whereDate('date', $this->day)
            ->where('session', $this->daySession)
            ->pluck('status', 'student_id');

        $this->statuses = $this->students
            ->mapWithKeys(fn (Student $student) => [$student->id => ($recorded[$student->id] ?? AttendanceStatus::Present)->value])
            ->all();
    }

    public function markAllPresent(): void
    {
        $this->statuses = array_map(fn () => AttendanceStatus::Present->value, $this->statuses);
    }

    public function save(): void
    {
        Gate::authorize('take-attendance', $this->schoolClass);
        abort_unless($this->editable, 403);

        $studentIds = $this->students->modelKeys();

        DB::transaction(function () use ($studentIds): void {
            $rollCall = RollCall::firstOrNew([
                'school_class_id' => $this->schoolClass->id,
                'date' => $this->day->toDateString(),
                'session' => $this->daySession,
            ]);
            $rollCall->forceFill(['taken_by' => auth()->id()])->touch();

            foreach ($studentIds as $studentId) {
                $status = AttendanceStatus::tryFrom($this->statuses[$studentId] ?? '') ?? AttendanceStatus::Present;
                $existing = Absence::where('student_id', $studentId)->whereDate('date', $this->day)->where('session', $this->daySession)->first();

                if ($status === AttendanceStatus::Present) {
                    $existing?->delete();

                    continue;
                }

                // A justification already given is kept when the student is still marked missing.
                ($existing ?? new Absence([
                    'student_id' => $studentId,
                    'date' => $this->day->toDateString(),
                    'session' => $this->daySession,
                ]))->fill(['school_class_id' => $this->schoolClass->id, 'status' => $status])->save();
            }
        });

        unset($this->rollCall);
        session()->flash('saved', 'Appel enregistré.');
    }

    public function render()
    {
        return $this->view()->title('Appel · '.$this->schoolClass->name);
    }
};
?>

<div>
    <x-ui.page-header eyebrow="Absences" :title="'Appel · '.$schoolClass->name" :description="\Illuminate\Support\Str::ucfirst($this->day->translatedFormat('l j F Y')).' · '.mb_strtolower($this->daySession->label())">
        <x-ui.button variant="secondary" :href="route('attendance.index', ['date' => $this->day->format('Y-m-d'), 'seance' => $this->daySession->value])">Toutes les classes</x-ui.button>
    </x-ui.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <input type="date" wire:model.live="date" max="{{ today()->format('Y-m-d') }}" aria-label="Jour" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
        <div class="flex rounded-lg bg-zinc-200/60 p-1 text-sm font-medium" role="tablist" aria-label="Demi-journée">
            @foreach (\App\Enums\DaySession::cases() as $option)
                <button
                    type="button"
                    role="tab"
                    wire:key="session-{{ $option->value }}"
                    wire:click="$set('session', '{{ $option->value }}')"
                    aria-selected="{{ $option === $this->daySession ? 'true' : 'false' }}"
                    @class(['rounded-md px-3 py-1.5 transition', 'bg-white text-zinc-900 shadow-xs' => $option === $this->daySession, 'text-zinc-600 hover:text-zinc-900' => $option !== $this->daySession])
                >{{ $option->label() }}</button>
            @endforeach
        </div>
        @if ($this->rollCall)
            <span class="text-sm text-zinc-500 sm:ml-auto">Appel fait par {{ $this->rollCall->taker?->name ?? '—' }} à {{ $this->rollCall->updated_at->format('H:i') }}{{ $this->editable ? ' · vous pouvez le corriger' : '' }}</span>
        @endif
    </div>

    @if (session('saved'))
        <div class="mb-4 flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm text-brand-900" role="status">
            <x-ui.icon name="check" class="size-4.5 text-brand-600" /> {{ session('saved') }}
        </div>
    @endif

    @unless ($this->editable)
        <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
            Cet appel date de plus de {{ $this::TEACHER_EDIT_DAYS }} jours : seule l'administration peut encore le modifier.
        </p>
    @endunless

    @if ($this->students->isEmpty())
        <x-ui.empty>Aucun élève inscrit dans cette classe.</x-ui.empty>
    @else
        @php($counts = array_count_values($statuses))
        <form wire:submit="save">
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white">
                <ul class="divide-y divide-zinc-100">
                    @foreach ($this->students as $student)
                        <li wire:key="student-{{ $student->id }}" class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                            <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $student->full_name }}</span>
                            <div class="flex rounded-lg bg-zinc-100 p-0.5 text-xs font-medium" role="radiogroup" aria-label="Présence de {{ $student->full_name }}">
                                @foreach (\App\Enums\AttendanceStatus::cases() as $option)
                                    @php($selected = ($statuses[$student->id] ?? 'present') === $option->value)
                                    <label
                                        wire:key="status-{{ $student->id }}-{{ $option->value }}"
                                        @class([
                                            'cursor-pointer rounded-md px-3 py-1.5 transition',
                                            'bg-brand-600 text-white' => $selected && $option === \App\Enums\AttendanceStatus::Present,
                                            'bg-red-600 text-white' => $selected && $option === \App\Enums\AttendanceStatus::Absent,
                                            'bg-amber-500 text-white' => $selected && $option === \App\Enums\AttendanceStatus::Late,
                                            'text-zinc-600 hover:text-zinc-900' => ! $selected,
                                            'pointer-events-none opacity-60' => ! $this->editable,
                                        ])
                                    >
                                        <input type="radio" class="sr-only" wire:model.live="statuses.{{ $student->id }}" value="{{ $option->value }}" @disabled(! $this->editable)>
                                        {{ $option->label() }}
                                    </label>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($this->editable)
                <div class="sticky bottom-0 mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-100/90 py-3 backdrop-blur">
                    <p class="text-sm text-zinc-600">
                        <strong>{{ $counts['present'] ?? 0 }}</strong> présent(s) ·
                        <strong class="text-red-600">{{ $counts['absent'] ?? 0 }}</strong> absent(s) ·
                        <strong class="text-amber-600">{{ $counts['late'] ?? 0 }}</strong> retard(s)
                    </p>
                    <div class="flex gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="markAllPresent">Tous présents</x-ui.button>
                        <x-ui.button icon="check">Enregistrer l'appel</x-ui.button>
                    </div>
                </div>
            @endif
        </form>
    @endif
</div>
