<?php

namespace App\Models;

use App\Enums\ClassLevel;
use App\Enums\Cycle;
use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['academic_year_id', 'name', 'level', 'capacity', 'head_teacher_id'])]
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    protected function casts(): array
    {
        return [
            'level' => ClassLevel::class,
        ];
    }

    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->whereIn($query->qualifyColumn('level'), array_column($section->levels(), 'value'));
    }

    /**
     * @return Attribute<Cycle, never>
     */
    protected function cycle(): Attribute
    {
        return Attribute::get(fn (): Cycle => $this->level->cycle());
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function headTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_teacher_id');
    }

    /**
     * @return BelongsToMany<Subject, $this>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subject')
            ->withPivot(['teacher_id', 'coefficient'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'enrollments')
            ->withPivot(['academic_year_id', 'enrolled_on', 'is_repeating'])
            ->withTimestamps();
    }
}
