<?php

namespace App\Enums;

/**
 * Mention given on a report card for an average out of 20.
 */
enum Mention: string
{
    case VeryGood = 'very_good';
    case Good = 'good';
    case FairlyGood = 'fairly_good';
    case Pass = 'pass';
    case Insufficient = 'insufficient';
    case Weak = 'weak';

    public static function fromAverage(float $averageOutOf20): self
    {
        return match (true) {
            $averageOutOf20 >= 16 => self::VeryGood,
            $averageOutOf20 >= 14 => self::Good,
            $averageOutOf20 >= 12 => self::FairlyGood,
            $averageOutOf20 >= 10 => self::Pass,
            $averageOutOf20 >= 8 => self::Insufficient,
            default => self::Weak,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::VeryGood => 'Très bien',
            self::Good => 'Bien',
            self::FairlyGood => 'Assez bien',
            self::Pass => 'Passable',
            self::Insufficient => 'Insuffisant',
            self::Weak => 'Faible',
        };
    }
}
