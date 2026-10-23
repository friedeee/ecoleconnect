<?php

namespace App\Enums;

/**
 * Class levels of the Beninese school system, in teaching order.
 */
enum ClassLevel: string
{
    case Nursery1 = 'Maternelle 1';
    case Nursery2 = 'Maternelle 2';
    case Ci = 'CI';
    case Cp = 'CP';
    case Ce1 = 'CE1';
    case Ce2 = 'CE2';
    case Cm1 = 'CM1';
    case Cm2 = 'CM2';
    case Sixth = '6e';
    case Fifth = '5e';
    case Fourth = '4e';
    case Third = '3e';
    case Second = '2nde';
    case First = '1ère';
    case Final = 'Tle';

    public function cycle(): Cycle
    {
        return match ($this) {
            self::Nursery1, self::Nursery2 => Cycle::Nursery,
            self::Ci, self::Cp, self::Ce1, self::Ce2, self::Cm1, self::Cm2 => Cycle::Primary,
            default => Cycle::Secondary,
        };
    }

    /**
     * Position in the school path, used to sort classes.
     */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    /**
     * Usual age of a pupil at the start of the year at this level.
     */
    public function typicalAge(): int
    {
        return 4 + $this->rank();
    }
}
