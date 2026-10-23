<?php

namespace App\Models;

use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Database\Factories\HomeworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exercises a teacher sets a class in a subject, with an optional worksheet file.
 */
#[Fillable(['school_class_id', 'subject_id', 'title', 'instructions', 'given_on', 'due_on'])]
class Homework extends Model
{
    /** @use HasFactory<HomeworkFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    protected $table = 'homework';

    protected function casts(): array
    {
        return [
            'given_on' => 'date',
            'due_on' => 'date',
        ];
    }

    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->whereHas('schoolClass');
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
