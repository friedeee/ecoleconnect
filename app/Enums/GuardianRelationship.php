<?php

namespace App\Enums;

enum GuardianRelationship: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Tutor = 'tutor';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Father => 'Père',
            self::Mother => 'Mère',
            self::Tutor => 'Tuteur',
            self::Other => 'Autre',
        };
    }
}
