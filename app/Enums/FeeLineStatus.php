<?php

namespace App\Enums;

/**
 * Where a line of a student's fee schedule stands.
 */
enum FeeLineStatus: string
{
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Upcoming = 'upcoming';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Soldé',
            self::Overdue => 'En retard',
            self::Upcoming => 'À venir',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'brand',
            self::Overdue => 'red',
            self::Upcoming => 'neutral',
        };
    }
}
