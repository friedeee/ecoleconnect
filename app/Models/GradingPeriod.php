<?php

namespace App\Models;

use App\Enums\Mention;
use App\Models\Concerns\BelongsToSchool;
use Carbon\CarbonInterface;
use Database\Factories\GradingPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trimester or semester of a school year, ending with a report card.
 */
#[Fillable(['academic_year_id', 'number', 'name', 'starts_on', 'ends_on'])]
class GradingPeriod extends Model
{
    /** @use HasFactory<GradingPeriodFactory> */
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * The year's periods, created from the school's choice of trimesters or semesters when missing.
     *
     * @return Collection<int, self>
     */
    public static function ensureFor(AcademicYear $year): Collection
    {
        $periods = self::withoutGlobalScopes()->whereBelongsTo($year)->orderBy('number')->get();

        if ($periods->isNotEmpty()) {
            return $periods;
        }

        $type = $year->school->period_type;
        $days = (int) $year->starts_on->diffInDays($year->ends_on) + 1;

        foreach (range(1, $type->count()) as $number) {
            $period = new self([
                'academic_year_id' => $year->id,
                'number' => $number,
                'name' => $type->periodName($number),
                'starts_on' => $year->starts_on->copy()->addDays(intdiv($days * ($number - 1), $type->count())),
                'ends_on' => $number === $type->count()
                    ? $year->ends_on
                    : $year->starts_on->copy()->addDays(intdiv($days * $number, $type->count()) - 1),
            ]);
            $period->forceFill(['school_id' => $year->school_id])->save();
            $periods->push($period);
        }

        return $periods;
    }

    /**
     * The period covering the given day, or the closest one.
     *
     * @param  Collection<int, self>  $periods
     */
    public static function covering(Collection $periods, ?CarbonInterface $day = null): ?self
    {
        $day ??= today();

        return $periods->first(fn (self $period) => $day->betweenIncluded($period->starts_on, $period->ends_on))
            ?? ($day->lt($periods->first()?->starts_on) ? $periods->first() : $periods->last());
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Everything a class's report cards need for this period. Averages are out of 20.
     *
     * @return array{
     *     subjects: list<array{subject: Subject, coefficient: int, teacher: ?User, average: ?float}>,
     *     students: list<array{student: Student, averages: array<int, ?float>, subject_ranks: array<int, int>, general: ?float, rank: ?int, mention: ?Mention}>,
     *     class_average: ?float,
     *     highest: ?float,
     *     lowest: ?float,
     *     graded: int,
     * }
     */
    public function classResults(SchoolClass $class): array
    {
        $cycle = $class->cycle;
        $subjects = $class->subjects()->orderBy('name')->get();
        $teachers = User::whereIn('id', $subjects->pluck('pivot.teacher_id')->filter())->get()->keyBy('id');
        $students = $class->students()->orderBy('last_name')->orderBy('first_name')->get();
        $assessments = Assessment::with('marks')
            ->whereBelongsTo($this)
            ->whereBelongsTo($class)
            ->get()
            ->groupBy('subject_id');

        $rows = [];

        foreach ($students as $student) {
            $averages = [];
            $weighted = 0.0;
            $coefficients = 0;

            foreach ($subjects as $subject) {
                $average = Assessment::averageFor($assessments->get($subject->id, new Collection), $student->id, $cycle);
                $averages[$subject->id] = $average === null ? null : round($average, 2);

                if ($average !== null) {
                    $weighted += $average * $subject->pivot->coefficient;
                    $coefficients += $subject->pivot->coefficient;
                }
            }

            $general = $coefficients > 0 ? round($weighted / $coefficients, 2) : null;

            $rows[$student->id] = [
                'student' => $student,
                'averages' => $averages,
                'subject_ranks' => [],
                'general' => $general,
                'rank' => null,
                'mention' => $general === null ? null : Mention::fromAverage($general),
            ];
        }

        foreach (self::rank(array_map(fn (array $row) => $row['general'], $rows)) as $studentId => $rank) {
            $rows[$studentId]['rank'] = $rank;
        }

        $subjectRows = [];

        foreach ($subjects as $subject) {
            $subjectAverages = array_map(fn (array $row) => $row['averages'][$subject->id], $rows);

            foreach (self::rank($subjectAverages) as $studentId => $rank) {
                $rows[$studentId]['subject_ranks'][$subject->id] = $rank;
            }

            $graded = array_filter($subjectAverages, fn (?float $average) => $average !== null);
            $subjectRows[] = [
                'subject' => $subject,
                'coefficient' => (int) $subject->pivot->coefficient,
                'teacher' => $teachers->get($subject->pivot->teacher_id),
                'average' => $graded === [] ? null : round(array_sum($graded) / count($graded), 2),
            ];
        }

        $generals = array_filter(array_column($rows, 'general'), fn (?float $general) => $general !== null);

        return [
            'subjects' => $subjectRows,
            'students' => array_values($rows),
            'class_average' => $generals === [] ? null : round(array_sum($generals) / count($generals), 2),
            'highest' => $generals === [] ? null : max($generals),
            'lowest' => $generals === [] ? null : min($generals),
            'graded' => count($generals),
        ];
    }

    /**
     * Ranks by descending value; equal values share a rank ("2e ex aequo") and the next rank is skipped.
     *
     * @param  array<int, ?float>  $values  keyed by student id
     * @return array<int, int>
     */
    protected static function rank(array $values): array
    {
        $values = array_filter($values, fn (?float $value) => $value !== null);
        arsort($values);

        $ranks = [];
        $position = 0;
        $previous = null;
        $rank = 0;

        foreach ($values as $studentId => $value) {
            $position++;

            if ($value !== $previous) {
                $rank = $position;
                $previous = $value;
            }

            $ranks[$studentId] = $rank;
        }

        return $ranks;
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return HasMany<Assessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }
}
