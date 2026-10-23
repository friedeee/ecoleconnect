<?php

namespace App\Models;

use App\Enums\AssessmentKind;
use App\Enums\Cycle;
use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An interrogation, a devoir or a primary evaluation given to a class in one subject.
 */
#[Fillable(['grading_period_id', 'school_class_id', 'subject_id', 'kind', 'title', 'held_on', 'out_of'])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    protected function casts(): array
    {
        return [
            'kind' => AssessmentKind::class,
            'held_on' => 'date',
            'out_of' => 'integer',
        ];
    }

    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->whereHas('schoolClass');
    }

    /**
     * A student's average out of 20 over the given assessments of one subject, or null without any score.
     *
     * In the secondary cycle the interrogations make one average which counts like a devoir:
     * (average of interrogations + devoir 1 + devoir 2) / 3. Elsewhere every evaluation counts the same.
     *
     * @param  Collection<int, Assessment>  $assessments  with their marks loaded
     */
    public static function averageFor(Collection $assessments, int $studentId, Cycle $cycle): ?float
    {
        $scores = [];

        foreach ($assessments as $assessment) {
            $mark = $assessment->marks->firstWhere('student_id', $studentId);

            if ($mark !== null && ! $mark->is_absent && $mark->score !== null) {
                $scores[] = ['kind' => $assessment->kind, 'score' => $mark->score / $assessment->out_of * 20];
            }
        }

        if ($scores === []) {
            return null;
        }

        if ($cycle === Cycle::Secondary) {
            $quizzes = array_column(array_filter($scores, fn (array $score) => $score['kind'] === AssessmentKind::Quiz), 'score');
            $components = array_column(array_filter($scores, fn (array $score) => $score['kind'] !== AssessmentKind::Quiz), 'score');

            if ($quizzes !== []) {
                $components[] = array_sum($quizzes) / count($quizzes);
            }

            return array_sum($components) / count($components);
        }

        return array_sum(array_column($scores, 'score')) / count($scores);
    }

    /**
     * @return HasMany<Mark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    /**
     * @return BelongsTo<GradingPeriod, $this>
     */
    public function gradingPeriod(): BelongsTo
    {
        return $this->belongsTo(GradingPeriod::class);
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
}
