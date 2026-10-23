<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\Section;
use App\Models\Concerns\BelongsToSchool;
use App\Models\Concerns\RestrictedToSection;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Money received for a student's fees. A payment is never deleted: it is cancelled with a reason.
 */
#[Fillable(['amount', 'paid_on', 'method', 'reference', 'notes'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToSchool, HasFactory, RestrictedToSection;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date',
            'method' => PaymentMethod::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Records a payment for the student's enrollment and gives it the school's next receipt number, e.g. "2026-00042".
     *
     * @param  array{amount: int, paid_on: string, method: PaymentMethod|string, reference?: ?string, notes?: ?string}  $attributes
     */
    public static function record(Enrollment $enrollment, array $attributes, User $receiver): self
    {
        return DB::transaction(function () use ($enrollment, $attributes, $receiver): self {
            // Locking the school row serialises receipt numbering between two cashiers.
            School::whereKey($enrollment->school_id)->lockForUpdate()->first();

            $prefix = $enrollment->academicYear->starts_on->format('Y').'-';
            $last = self::withoutGlobalScopes()
                ->where('school_id', $enrollment->school_id)
                ->where('receipt_number', 'like', $prefix.'%')
                ->max('receipt_number');
            $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            $payment = new self($attributes);
            $payment->forceFill([
                'school_id' => $enrollment->school_id,
                'academic_year_id' => $enrollment->academic_year_id,
                'student_id' => $enrollment->student_id,
                'receipt_number' => $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
                'received_by' => $receiver->id,
            ])->save();

            return $payment;
        });
    }

    public function cancel(User $user, string $reason): void
    {
        $this->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancellation_reason' => $reason,
        ])->save();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Keeps the payments of students the user can see.
     */
    public function restrictToSection(Builder $query, Section $section): void
    {
        $query->whereHas('student');
    }

    #[Scope]
    protected function valid(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('cancelled_at'));
    }

    /**
     * The amount written out in French, as on a receipt: "quatre-vingt-cinq mille".
     */
    public static function amountInWords(int $amount): string
    {
        if ($amount === 0) {
            return 'zéro';
        }

        $millions = intdiv($amount, 1_000_000);
        $thousands = intdiv($amount % 1_000_000, 1000);
        $rest = $amount % 1000;

        return implode(' ', array_filter([
            $millions > 0 ? ($millions === 1 ? 'un million' : self::belowThousandInWords($millions, true).' millions') : null,
            $thousands > 0 ? ($thousands === 1 ? 'mille' : self::belowThousandInWords($thousands, false).' mille') : null,
            $rest > 0 ? self::belowThousandInWords($rest, true) : null,
        ]));
    }

    /**
     * @param  bool  $plural  "cents" and "quatre-vingts" take an s only when nothing follows them but a noun like "millions".
     */
    protected static function belowThousandInWords(int $number, bool $plural): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;

        $words = match (true) {
            $hundreds === 0 => null,
            $hundreds === 1 => 'cent',
            default => self::UNITS[$hundreds].' cent'.($rest === 0 && $plural ? 's' : ''),
        };

        return implode(' ', array_filter([$words, $rest > 0 ? self::belowHundredInWords($rest, $plural) : null]));
    }

    protected static function belowHundredInWords(int $number, bool $plural): string
    {
        if ($number < 17) {
            return self::UNITS[$number];
        }

        if ($number < 20) {
            return 'dix-'.self::UNITS[$number - 10];
        }

        $tens = intdiv($number, 10);
        $unit = $number % 10;

        return match (true) {
            // 70-79 and 90-99 are counted from 60 and 80: soixante-douze, quatre-vingt-quinze.
            $tens === 7 => 'soixante'.($number === 71 ? ' et ' : '-').self::belowHundredInWords($number - 60, $plural),
            $tens === 9 => 'quatre-vingt-'.self::belowHundredInWords($number - 80, $plural),
            $tens === 8 => $unit === 0 ? 'quatre-vingt'.($plural ? 's' : '') : 'quatre-vingt-'.self::UNITS[$unit],
            $unit === 0 => self::TENS[$tens],
            $unit === 1 => self::TENS[$tens].' et un',
            default => self::TENS[$tens].'-'.self::UNITS[$unit],
        };
    }

    /** @var list<string> */
    protected const UNITS = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];

    /** @var array<int, string> */
    protected const TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

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
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
