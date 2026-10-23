<?php

namespace App\Enums;

/**
 * Half of a school day, the unit of roll calls and absences.
 */
enum DaySession: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';

    public function label(): string
    {
        return match ($this) {
            self::Morning => 'Matin',
            self::Afternoon => 'Après-midi',
        };
    }

    public static function now(): self
    {
        return now()->hour < 13 ? self::Morning : self::Afternoon;
    }
}
