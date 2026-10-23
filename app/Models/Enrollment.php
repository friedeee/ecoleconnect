<?php

namespace App\Models;

use App\Enums\FeeLineStatus;
use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Carbon\CarbonInterface;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['student_id', 'academic_year_id', 'school_class_id', 'enrolled_on', 'is_repeating'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'date',
            'is_repeating' => 'boolean',
        ];
    }

    /**
     * Keeps the enrollments in a class of the section (classes are themselves restricted).
     */
    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->whereHas('schoolClass');
    }

    /**
     * Adds what the enrollment owes for the year (fees_due), owes by the given day (fees_due_to_date)
     * and has paid (fees_paid), computed in SQL so that unpaid fees can be listed and sorted.
     */
    #[Scope]
    protected function withFeeTotals(Builder $query, ?CarbonInterface $asOf = null): void
    {
        $asOf ??= today();

        $owed = fn (bool $dueByThen) => FeeItem::withoutGlobalScopes()
            ->selectRaw('coalesce(sum(fee_items.amount), 0)')
            ->whereColumn('fee_items.academic_year_id', 'enrollments.academic_year_id')
            ->whereColumn('fee_items.level', 'school_classes.level')
            ->where(fn (Builder $query) => $query->where('fee_items.is_optional', false)->orWhereExists(
                fn ($query) => $query->selectRaw('1')->from('enrollment_fee_item')
                    ->whereColumn('enrollment_fee_item.fee_item_id', 'fee_items.id')
                    ->whereColumn('enrollment_fee_item.enrollment_id', 'enrollments.id'),
            ))
            ->when($dueByThen, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('fee_items.due_on')
                ->orWhere('fee_items.due_on', '<=', $asOf->toDateString())));

        $paid = Payment::withoutGlobalScopes()
            ->selectRaw('coalesce(sum(payments.amount), 0)')
            ->whereColumn('payments.student_id', 'enrollments.student_id')
            ->whereColumn('payments.academic_year_id', 'enrollments.academic_year_id')
            ->whereNull('payments.cancelled_at');

        $query->join('school_classes', 'school_classes.id', '=', 'enrollments.school_class_id')
            ->select('enrollments.*')
            ->selectSub($owed(false), 'fees_due')
            ->selectSub($owed(true), 'fees_due_to_date')
            ->selectSub($paid, 'fees_paid');
    }

    /**
     * Fee lines of the enrollment: its level's compulsory fees and the optional ones it takes, in payment order.
     *
     * @return Collection<int, FeeItem>
     */
    public function feeItems(): Collection
    {
        return FeeItem::where('academic_year_id', $this->academic_year_id)
            ->where('level', $this->schoolClass->level)
            ->where(fn (Builder $query) => $query->where('is_optional', false)->orWhereIn('id', $this->optionalFees()->select('fee_items.id')))
            ->orderByRaw('due_on asc nulls first')
            ->orderBy('id')
            ->get();
    }

    /**
     * The fee lines with what has been paid on each. Payments settle the earliest lines first.
     *
     * @return array{lines: list<array{item: FeeItem, paid: int, remaining: int, status: FeeLineStatus}>, due: int, paid: int, balance: int, overdue: int}
     */
    public function feeStatement(?CarbonInterface $today = null): array
    {
        $today ??= today();
        $paid = (int) Payment::valid()
            ->where('student_id', $this->student_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->sum('amount');

        $left = $paid;
        $lines = [];
        $overdue = 0;

        foreach ($this->feeItems() as $item) {
            $applied = min($left, $item->amount);
            $left -= $applied;
            $remaining = $item->amount - $applied;

            $status = match (true) {
                $remaining === 0 => FeeLineStatus::Paid,
                $item->isDueBy($today) => FeeLineStatus::Overdue,
                default => FeeLineStatus::Upcoming,
            };

            if ($status === FeeLineStatus::Overdue) {
                $overdue += $remaining;
            }

            $lines[] = ['item' => $item, 'paid' => $applied, 'remaining' => $remaining, 'status' => $status];
        }

        $due = array_sum(array_map(fn (array $line) => $line['item']->amount, $lines));

        return ['lines' => $lines, 'due' => $due, 'paid' => $paid, 'balance' => $due - $paid, 'overdue' => $overdue];
    }

    /**
     * Optional fees (uniform, canteen, transport…) the student takes this year.
     *
     * @return BelongsToMany<FeeItem, $this>
     */
    public function optionalFees(): BelongsToMany
    {
        return $this->belongsToMany(FeeItem::class)->withTimestamps();
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }
}
