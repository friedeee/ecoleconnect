<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\DaySession;
use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Carbon\CarbonInterface;
use Database\Factories\AbsenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student missing, or late, at a half-day roll call.
 */
#[Fillable(['student_id', 'school_class_id', 'date', 'session', 'status', 'is_justified', 'reason'])]
class Absence extends Model
{
    /** @use HasFactory<AbsenceFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'session' => DaySession::class,
            'status' => AttendanceStatus::class,
            'is_justified' => 'boolean',
        ];
    }

    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->whereHas('schoolClass');
    }

    /**
     * Absences, justified absences and lateness per student between two days.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array{absent: int, justified: int, late: int}>
     */
    public static function countsFor(array $studentIds, CarbonInterface $from, CarbonInterface $to): array
    {
        return self::whereIn('student_id', $studentIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('student_id')
            ->selectRaw("count(*) filter (where status = 'absent') as absent")
            ->selectRaw("count(*) filter (where status = 'absent' and is_justified) as justified")
            ->selectRaw("count(*) filter (where status = 'late') as late")
            ->groupBy('student_id')
            ->get()
            ->mapWithKeys(fn (self $row) => [$row->student_id => ['absent' => (int) $row->absent, 'justified' => (int) $row->justified, 'late' => (int) $row->late]])
            ->all();
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function justifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by');
    }
}
