<?php

namespace App\Enums;

/**
 * Part of a school run by its own management: nursery and primary on one side, secondary on the other.
 */
enum Section: string
{
    case Basic = 'basic';
    case Secondary = 'secondary';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Maternelle et primaire',
            self::Secondary => 'Secondaire',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Basic => 'Primaire',
            self::Secondary => 'Secondaire',
        };
    }

    /**
     * @return list<Cycle>
     */
    public function cycles(): array
    {
        return match ($this) {
            self::Basic => [Cycle::Nursery, Cycle::Primary],
            self::Secondary => [Cycle::Secondary],
        };
    }

    /**
     * @return list<ClassLevel>
     */
    public function levels(): array
    {
        return array_merge(...array_map(fn (Cycle $cycle) => $cycle->levels(), $this->cycles()));
    }
}
