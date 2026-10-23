<?php

namespace App\Enums;

/**
 * How a school splits its year into report card periods.
 */
enum PeriodType: string
{
    case Trimester = 'trimester';
    case Semester = 'semester';

    public function label(): string
    {
        return match ($this) {
            self::Trimester => 'Trimestres (3 bulletins)',
            self::Semester => 'Semestres (2 bulletins)',
        };
    }

    public function count(): int
    {
        return $this === self::Trimester ? 3 : 2;
    }

    public function periodName(int $number): string
    {
        $ordinal = $number === 1 ? '1er' : $number.'e';

        return $ordinal.' '.($this === self::Trimester ? 'trimestre' : 'semestre');
    }
}
