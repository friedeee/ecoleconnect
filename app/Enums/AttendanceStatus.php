<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Absent => 'Absent',
            self::Late => 'En retard',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Present => 'brand',
            self::Absent => 'red',
            self::Late => 'amber',
        };
    }
}
