<?php

namespace App\Models;

use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Database\Factories\TimetableEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly lesson: a class has a subject with a teacher, on a day, between two times.
 */
#[Fillable(['school_class_id', 'subject_id', 'teacher_id', 'day', 'starts_at', 'ends_at', 'room'])]
class TimetableEntry extends Model
{
    /** @use HasFactory<TimetableEntryFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    /** @var array<int, string> */
    public const DAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi'];

    protected function casts(): array
    {
        return [
            'day' => 'integer',
        ];
    }

    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->whereHas('schoolClass');
    }

    /**
     * Why the lesson cannot take place: the class, the teacher or the room is already busy at that time.
     * Other sections and schools are checked too, since a teacher or a room is shared by the whole school.
     *
     * @return list<string>
     */
    public function conflicts(): array
    {
        $overlapping = self::withoutGlobalScopes()
            ->with(['schoolClass' => fn ($query) => $query->withoutGlobalScopes(), 'subject' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('school_id', $this->school_id)
            ->where('day', $this->day)
            ->where('starts_at', '<', $this->ends_at)
            ->where('ends_at', '>', $this->starts_at)
            ->whereHas('schoolClass', fn (Builder $query) => $query->withoutGlobalScopes()->where('academic_year_id', SchoolClass::withoutGlobalScopes()->whereKey($this->school_class_id)->value('academic_year_id')))
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->id))
            ->get();

        $messages = [];

        foreach ($overlapping as $other) {
            $slot = substr($other->starts_at, 0, 5).'–'.substr($other->ends_at, 0, 5);

            if ($other->school_class_id === $this->school_class_id) {
                $messages[] = "La classe a déjà {$other->subject->name} de {$slot}.";
            }

            if ($this->teacher_id !== null && $other->teacher_id === $this->teacher_id) {
                $messages[] = "L'enseignant est déjà en {$other->schoolClass->name} ({$other->subject->name}) de {$slot}.";
            }

            if ($this->room !== null && $other->room !== null && mb_strtolower($other->room) === mb_strtolower($this->room)) {
                $messages[] = "La salle {$this->room} est déjà occupée par la {$other->schoolClass->name} de {$slot}.";
            }
        }

        return array_values(array_unique($messages));
    }

    public function durationInMinutes(): int
    {
        return (int) ((strtotime($this->ends_at) - strtotime($this->starts_at)) / 60);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
