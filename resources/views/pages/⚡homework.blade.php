<?php

use App\Enums\Role;
use App\Models\Homework;
use App\Models\SchoolClass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Exercices')] class extends Component
{
    use WithFileUploads, WithPagination;

    #[Url(as: 'classe', except: '')]
    public string $classId = '';

    public bool $showForm = false;

    /** "classId-subjectId" of the class and subject the exercise is for. */
    public string $assignment = '';

    public string $title = '';

    public string $instructions = '';

    public string $given_on = '';

    public string $due_on = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $attachment = null;

    public function updatedClassId(): void
    {
        $this->resetPage();
    }

    /**
     * Classes and subjects the user may set exercises in: those they teach, or all for admins.
     *
     * @return array<string, string> "classId-subjectId" => "6e A — Mathématiques"
     */
    #[Computed]
    public function assignments(): array
    {
        $classes = SchoolClass::whereHas('academicYear', fn ($query) => $query->where('is_current', true))
            ->with(['subjects' => fn ($query) => $query->orderBy('name')])
            ->get()
            ->sortBy(fn (SchoolClass $class) => [$class->level->rank(), $class->name]);

        $options = [];

        foreach ($classes as $class) {
            foreach ($class->subjects as $subject) {
                if (Gate::allows('grade', [$class, $subject])) {
                    $options[$class->id.'-'.$subject->id] = $class->name.' — '.$subject->name;
                }
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function classes(): array
    {
        return collect($this->assignments)
            ->mapWithKeys(fn (string $label, string $key) => [(int) explode('-', $key)[0] => explode(' — ', $label)[0]])
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, Homework>
     */
    #[Computed]
    public function exercises(): LengthAwarePaginator
    {
        return Homework::with(['schoolClass', 'subject', 'author'])
            ->whereIn('school_class_id', array_keys($this->classes))
            ->when($this->classId !== '', fn ($query) => $query->where('school_class_id', (int) $this->classId))
            ->latest('given_on')
            ->latest('id')
            ->paginate(20);
    }

    public function openForm(): void
    {
        $this->resetValidation();
        $this->reset(['assignment', 'title', 'instructions', 'due_on', 'attachment']);
        $this->given_on = today()->format('Y-m-d');
        $this->showForm = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'assignment' => ['required', Rule::in(array_keys($this->assignments))],
            'title' => ['required', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'given_on' => ['required', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:given_on'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'attachment.mimes' => 'Le fichier doit être un PDF ou une photo (JPG, PNG).',
            'attachment.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
        ], ['assignment' => 'classe et matière', 'title' => 'titre', 'given_on' => 'date', 'due_on' => 'date à rendre', 'attachment' => 'fichier']);

        [$classId, $subjectId] = array_map('intval', explode('-', $validated['assignment']));

        $homework = new Homework([
            'school_class_id' => $classId,
            'subject_id' => $subjectId,
            'title' => trim($validated['title']),
            'instructions' => trim($validated['instructions']) ?: null,
            'given_on' => $validated['given_on'],
            'due_on' => $validated['due_on'] ?: null,
        ]);
        $homework->created_by = auth()->id();

        if ($this->attachment) {
            $homework->attachment_path = $this->attachment->store('homework/'.auth()->user()->school_id, 'local');
            $homework->attachment_name = $this->attachment->getClientOriginalName();
        }

        $homework->save();

        $this->showForm = false;
        $this->reset(['attachment']);
        unset($this->exercises);
    }

    /**
     * Authors remove their own exercises; admins remove any.
     */
    public function delete(int $homeworkId): void
    {
        $homework = Homework::whereIn('school_class_id', array_keys($this->classes))->findOrFail($homeworkId);

        abort_unless(auth()->user()->hasRole(Role::Admin) || $homework->created_by === auth()->id(), 403);

        if ($homework->attachment_path) {
            Storage::disk('local')->delete($homework->attachment_path);
        }

        $homework->delete();
        unset($this->exercises);
    }
};
?>

<div>
    <x-ui.page-header title="Exercices" description="Les exercices et devoirs de maison, visibles par les parents dans leur espace.">
        @if (! $showForm && $this->assignments !== [])
            <x-ui.button type="button" wire:click="openForm" icon="plus">Donner un exercice</x-ui.button>
        @endif
    </x-ui.page-header>

    @if ($showForm)
        <x-ui.card title="Nouvel exercice" class="mb-6">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <x-ui.select label="Classe et matière" name="assignment" placeholder="Choisir…" class="sm:col-span-2">
                    @foreach ($this->assignments as $value => $label)
                        <option value="{{ $value }}" wire:key="assignment-{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Titre" name="title" placeholder="ex. Exercices 3 à 7 page 42" class="sm:col-span-2" />
                <x-ui.field label="Consignes (facultatif)" name="instructions" class="sm:col-span-2">
                    <textarea id="instructions" wire:model="instructions" rows="4" class="block w-full rounded-xl border-0 px-3.5 py-2.5 text-sm text-zinc-900 shadow-xs ring-1 ring-zinc-300 ring-inset placeholder:text-zinc-400 focus:ring-2 focus:ring-brand-500 focus:ring-inset focus:outline-none" placeholder="Ce que l'élève doit faire, le matériel nécessaire…"></textarea>
                </x-ui.field>
                <x-ui.input label="Donné le" name="given_on" type="date" />
                <x-ui.input label="À rendre le (facultatif)" name="due_on" type="date" />
                <x-ui.field label="Fichier (facultatif)" name="attachment" hint="Fiche d'exercices en PDF ou photo, 5 Mo maximum." class="sm:col-span-2">
                    <input id="attachment" type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                    <p wire:loading wire:target="attachment" class="mt-1 text-xs text-zinc-500">Envoi du fichier…</p>
                </x-ui.field>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showForm', false)">Annuler</x-ui.button>
                    <x-ui.button wire:loading.attr="disabled" wire:target="attachment">Publier</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($this->assignments === [])
        <x-ui.empty icon="book-open" title="Aucune matière">Aucune matière ne vous est attribuée. L'administration vous assigne vos matières depuis la fiche de chaque classe.</x-ui.empty>
    @else
        <div class="mb-4">
            <select wire:model.live="classId" aria-label="Filtrer par classe" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-brand-500 focus:outline-none sm:w-48">
                <option value="">Toutes mes classes</option>
                @foreach ($this->classes as $id => $name)
                    <option value="{{ $id }}" wire:key="class-{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        @if ($this->exercises->isEmpty())
            <x-ui.empty icon="book-open" title="Aucun exercice">Aucun exercice donné pour l'instant.</x-ui.empty>
        @else
            <div class="space-y-3">
                @foreach ($this->exercises as $exercise)
                    <article wire:key="exercise-{{ $exercise->id }}" class="card p-4">
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <x-ui.badge>{{ $exercise->schoolClass->name }}</x-ui.badge>
                            <x-ui.badge tone="brand">{{ $exercise->subject->name }}</x-ui.badge>
                            <span class="text-zinc-500">Donné le {{ $exercise->given_on->format('d/m/Y') }}{{ $exercise->due_on ? ' · à rendre le '.$exercise->due_on->format('d/m/Y') : '' }}</span>
                            @if (auth()->user()->hasRole(\App\Enums\Role::Admin) || $exercise->created_by === auth()->id())
                                <button type="button" wire:click="delete({{ $exercise->id }})" wire:confirm="Supprimer l'exercice « {{ $exercise->title }} » ? Les parents ne le verront plus." class="ml-auto font-medium text-red-600 hover:underline">Supprimer</button>
                            @endif
                        </div>
                        <h2 class="mt-2 font-semibold">{{ $exercise->title }}</h2>
                        @if ($exercise->instructions)
                            <p class="mt-1 whitespace-pre-line text-sm text-zinc-600">{{ $exercise->instructions }}</p>
                        @endif
                        <p class="mt-2 flex flex-wrap gap-3 text-xs text-zinc-500">
                            <span>{{ $exercise->author?->name }}</span>
                            @if ($exercise->attachment_path)
                                <a href="{{ route('homework.file', $exercise) }}" class="font-semibold text-brand-700 hover:underline">{{ $exercise->attachment_name }}</a>
                            @endif
                        </p>
                    </article>
                @endforeach
            </div>

            <x-ui.pagination :paginator="$this->exercises" noun="exercices" class="mt-4" />
        @endif
    @endif
</div>
