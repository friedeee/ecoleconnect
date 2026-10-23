<?php

namespace App\Models;

use App\Enums\Cycle;
use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'code', 'cycle'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    protected function casts(): array
    {
        return [
            'cycle' => Cycle::class,
        ];
    }

    /**
     * Keeps the subjects of the section's cycles and those shared by every cycle.
     */
    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereIn($query->qualifyColumn('cycle'), array_column($section->cycles(), 'value'))
            ->orWhereNull($query->qualifyColumn('cycle')));
    }

    /**
     * Subjects taught in the given cycle, including those shared by every cycle.
     */
    #[Scope]
    protected function forCycle(Builder $query, Cycle $cycle): void
    {
        $query->where(fn (Builder $query) => $query->where('cycle', $cycle)->orWhereNull('cycle'));
    }

    /**
     * @return BelongsToMany<SchoolClass, $this>
     */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_subject')
            ->withPivot(['teacher_id', 'coefficient'])
            ->withTimestamps();
    }
}
