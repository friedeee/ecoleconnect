<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Database\Factories\MarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's score in an assessment, or their absence from it.
 */
#[Fillable(['assessment_id', 'student_id', 'score', 'is_absent'])]
class Mark extends Model
{
    /** @use HasFactory<MarkFactory> */
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'is_absent' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
