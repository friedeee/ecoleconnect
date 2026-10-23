<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Active = 'active';
    case Left = 'left';
    case Graduated = 'graduated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Left => 'Parti',
            self::Graduated => 'Diplômé',
        };
    }
}
