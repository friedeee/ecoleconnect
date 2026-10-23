<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Accountant = 'accountant';
    case Parent = 'parent';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super administrateur',
            self::Admin => 'Administrateur',
            self::Teacher => 'Enseignant',
            self::Accountant => 'Comptable',
            self::Parent => 'Parent',
        };
    }

    /**
     * Roles a school administrator may assign to staff accounts.
     *
     * @return list<self>
     */
    public static function staffRoles(): array
    {
        return [self::Admin, self::Teacher, self::Accountant];
    }
}
