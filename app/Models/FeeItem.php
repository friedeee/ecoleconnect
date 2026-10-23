<?php

namespace App\Models;

use App\Enums\ClassLevel;
use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Database\Factories\FeeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a level's fee schedule: registration, a tuition instalment, or an optional fee.
 */
#[Fillable(['academic_year_id', 'level', 'label', 'amount', 'due_on', 'is_optional'])]
class FeeItem extends Model
{
    /** @use HasFactory<FeeItemFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    protected function casts(): array
    {
        return [
            'level' => ClassLevel::class,
            'amount' => 'integer',
            'due_on' => 'date',
            'is_optional' => 'boolean',
        ];
    }

    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->whereIn($query->qualifyColumn('level'), array_column($section->levels(), 'value'));
    }

    /**
     * Whether the amount must be paid by the given day. Items without a due date are due at once.
     */
    public function isDueBy(\DateTimeInterface $day): bool
    {
        return $this->due_on === null || $this->due_on->lte($day);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
