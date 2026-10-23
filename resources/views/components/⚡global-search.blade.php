<?php

use App\Enums\Role;
use App\Enums\Section;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** Results shown per group, to keep the list short. */
    public const LIMIT = 5;

    public string $query = '';

    /**
     * Matching pages and records, grouped. Records are limited to the user's school and section by the model scopes.
     *
     * @return array<string, array{icon: string, items: list<array{label: string, detail: ?string, url: string}>}>
     */
    #[Computed]
    public function results(): array
    {
        $term = trim($this->query);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return array_filter([
            'Pages' => ['icon' => 'chevron-right', 'items' => $this->pages($term)],
            'Élèves' => ['icon' => 'users', 'items' => Gate::allows('view-students') ? $this->students($term) : []],
            'Classes' => ['icon' => 'academic-cap', 'items' => Gate::allows('view-students') ? $this->classes($like) : []],
            'Matières' => ['icon' => 'book-open', 'items' => Gate::allows('manage-school') ? $this->subjects($like) : []],
            'Personnel' => ['icon' => 'briefcase', 'items' => Gate::allows('manage-school') ? $this->staff($like) : []],
        ], fn (array $group) => $group['items'] !== []);
    }

    /**
     * Pages the user may open, matched on their name and a few usual words, ignoring accents and case.
     *
     * @return list<array{label: string, detail: ?string, url: string}>
     */
    protected function pages(string $term): array
    {
        $pages = [
            ['label' => 'Mon profil', 'keywords' => 'compte mot de passe informations telephone', 'route' => 'profile', 'ability' => null],
            ['label' => 'Tableau de bord', 'keywords' => 'accueil statistiques', 'route' => 'dashboard', 'ability' => null],
            ['label' => 'Élèves', 'keywords' => 'liste eleves', 'route' => 'students.index', 'ability' => 'view-students'],
            ['label' => 'Inscrire un élève', 'keywords' => 'nouvel eleve inscription ajouter', 'route' => 'students.create', 'ability' => 'manage-students'],
            ['label' => 'Classes', 'keywords' => 'salles niveaux', 'route' => 'classes.index', 'ability' => 'view-students'],
            ['label' => 'Notes', 'keywords' => 'saisie notes interrogations devoirs evaluations moyennes', 'route' => 'grades.index', 'ability' => 'view-grades'],
            ['label' => 'Bulletins', 'keywords' => 'bulletins rangs moyennes trimestre semestre resultats', 'route' => 'grades.reports', 'ability' => 'manage-report-cards'],
            ['label' => 'Exercices', 'keywords' => 'devoirs maison cahier de textes travail a faire', 'route' => 'homework', 'ability' => 'view-grades'],
            ['label' => 'Absences', 'keywords' => 'appel retards presences absents', 'route' => 'attendance.index', 'ability' => 'view-attendance'],
            ['label' => 'Emploi du temps', 'keywords' => 'horaires cours planning salles semaine', 'route' => 'timetable', 'ability' => 'view-timetable'],
            ['label' => 'Frais de scolarité', 'keywords' => 'paiements impayes encaissements recus caisse scolarite', 'route' => 'fees.index', 'ability' => 'view-fees'],
            ['label' => 'Barème des frais', 'keywords' => 'tarifs tranches montants scolarite', 'route' => 'fees.schedule', 'ability' => 'manage-fee-schedule'],
            ['label' => 'Matières', 'keywords' => 'disciplines cours coefficients', 'route' => 'subjects.index', 'ability' => 'manage-school'],
            ['label' => 'Personnel', 'keywords' => 'enseignants professeurs comptes utilisateurs', 'route' => 'staff.index', 'ability' => 'manage-school'],
            ['label' => 'Paramètres', 'keywords' => 'ecole annee scolaire reglages', 'route' => 'settings', 'ability' => 'manage-whole-school'],
        ];

        $normalize = fn (string $value): string => Str::lower(Str::ascii($value));
        $needle = $normalize($term);

        return collect($pages)
            ->filter(fn (array $page) => ($page['ability'] === null || Gate::allows($page['ability']))
                && str_contains($normalize($page['label'].' '.$page['keywords']), $needle))
            ->map(fn (array $page) => ['label' => $page['label'], 'detail' => null, 'url' => route($page['route'])])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, detail: ?string, url: string}>
     */
    protected function students(string $term): array
    {
        return Student::search($term)
            ->with('currentEnrollment.schoolClass')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Student $student) => [
                'label' => $student->full_name,
                'detail' => $student->registration_number.' · '.($student->currentEnrollment?->schoolClass->name ?? 'Non inscrit'),
                'url' => route('students.show', $student),
            ])
            ->all();
    }

    /**
     * @return list<array{label: string, detail: ?string, url: string}>
     */
    protected function classes(string $like): array
    {
        return SchoolClass::query()
            ->whereHas('academicYear', fn (Builder $query) => $query->where('is_current', true))
            ->whereLike('name', $like)
            ->withCount('enrollments')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (SchoolClass $class) => [
                'label' => $class->name,
                'detail' => $class->cycle->label().' · '.$class->enrollments_count.' élève(s)',
                'url' => route('classes.show', $class),
            ])
            ->all();
    }

    /**
     * @return list<array{label: string, detail: ?string, url: string}>
     */
    protected function subjects(string $like): array
    {
        return Subject::query()
            ->where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('code', $like))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Subject $subject) => [
                'label' => $subject->name,
                'detail' => $subject->code.' · '.($subject->cycle?->label() ?? 'Tous les cycles'),
                'url' => route('subjects.index'),
            ])
            ->all();
    }

    /**
     * Staff accounts, as listed on the staff page.
     *
     * @return list<array{label: string, detail: ?string, url: string}>
     */
    protected function staff(string $like): array
    {
        return User::where('school_id', auth()->user()->school_id)
            ->whereIn('role', array_map(fn (Role $role) => $role->value, Role::staffRoles()))
            ->when(auth()->user()->activeSection(), fn (Builder $query, Section $section) => $query->where('section', $section))
            ->where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('email', $like))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (User $user) => [
                'label' => $user->name,
                'detail' => $user->role->label().' · '.$user->email,
                'url' => route('staff.index'),
            ])
            ->all();
    }
};
?>

<div
    x-data="{
        open: false,
        active: 0,
        items() { return [...this.$el.querySelectorAll('[data-result]')] },
        move(step) {
            const items = this.items();
            if (items.length === 0) return;
            this.open = true;
            this.active = (this.active + step + items.length) % items.length;
            items[this.active].scrollIntoView({ block: 'nearest' });
        },
        go() { this.items()[this.active]?.click() },
    }"
    x-on:keydown.window.ctrl.k.prevent="$refs.input.focus(); $refs.input.select()"
    x-on:keydown.window.meta.k.prevent="$refs.input.focus(); $refs.input.select()"
    x-on:click.outside="open = false"
    class="relative w-full max-w-md"
>
    <label for="global-search" class="sr-only">Rechercher dans l'école</label>
    <div class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-zinc-400">
        <x-ui.icon name="search" class="size-4" wire:loading.remove wire:target="query" />
        <svg wire:loading wire:target="query" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" class="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
        </svg>
    </div>
    <input
        x-ref="input"
        id="global-search"
        type="search"
        autocomplete="off"
        wire:model.live.debounce.250ms="query"
        x-on:focus="open = true"
        x-on:input="open = true; active = 0"
        x-on:keydown.down.prevent="move(1)"
        x-on:keydown.up.prevent="move(-1)"
        x-on:keydown.enter.prevent="go()"
        x-on:keydown.escape="open = false; $refs.input.blur()"
        placeholder="Rechercher un élève, une classe, une page…"
        role="combobox"
        aria-controls="global-search-results"
        x-bind:aria-expanded="open"
        class="block w-full rounded-xl border-0 bg-zinc-100 py-2 pr-14 pl-9 text-sm text-zinc-900 placeholder:text-zinc-500 focus:bg-white focus:ring-2 focus:ring-brand-500 focus:outline-none [&::-webkit-search-cancel-button]:hidden"
    >
    <kbd class="pointer-events-none absolute inset-y-0 right-2.5 my-auto hidden h-5 items-center rounded border border-zinc-300 bg-white px-1.5 font-sans text-[10px] font-medium text-zinc-500 sm:flex">Ctrl K</kbd>

    <div
        x-cloak
        x-show="open && $wire.query.trim().length >= 2"
        x-transition.opacity.duration.100ms
        id="global-search-results"
        role="listbox"
        class="absolute inset-x-0 top-full z-30 mt-2 max-h-[70vh] overflow-y-auto rounded-xl border border-zinc-200 bg-white p-1.5 shadow-xl shadow-zinc-900/10 sm:-right-24"
    >
        @if ($this->results === [])
            <p class="px-3 py-6 text-center text-sm text-zinc-500" wire:loading.remove wire:target="query">
                Aucun résultat pour « {{ $query }} ».
            </p>
        @else
            @php($index = 0)
            @foreach ($this->results as $group => $section)
                <div wire:key="group-{{ $loop->index }}" @class(['mt-1.5 border-t border-zinc-100 pt-1.5' => ! $loop->first])>
                    <p class="px-3 pt-1.5 pb-1 text-[11px] font-semibold uppercase tracking-wider text-zinc-400">{{ $group }}</p>
                    @foreach ($section['items'] as $item)
                        <a
                            href="{{ $item['url'] }}"
                            wire:navigate
                            wire:key="result-{{ $index }}"
                            data-result
                            role="option"
                            x-on:mouseenter="active = {{ $index }}"
                            x-on:click="open = false"
                            x-bind:class="active === {{ $index }} ? 'bg-brand-50 text-brand-900' : 'text-zinc-700'"
                            x-bind:aria-selected="active === {{ $index }}"
                            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm"
                        >
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500">
                                <x-ui.icon :name="$section['icon']" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ $item['label'] }}</span>
                                @if ($item['detail'])
                                    <span class="block truncate text-xs text-zinc-500">{{ $item['detail'] }}</span>
                                @endif
                            </span>
                        </a>
                        @php($index++)
                    @endforeach
                </div>
            @endforeach
        @endif
    </div>
</div>
