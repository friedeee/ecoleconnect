<?php

namespace App\Enums;

enum Cycle: string
{
    case Nursery = 'nursery';
    case Primary = 'primary';
    case Secondary = 'secondary';

    public function label(): string
    {
        return match ($this) {
            self::Nursery => 'Maternelle',
            self::Primary => 'Primaire',
            self::Secondary => 'Secondaire',
        };
    }

    /**
     * Badge color used to tell cycles apart in the interface.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Nursery => 'violet',
            self::Primary => 'sky',
            self::Secondary => 'brand',
        };
    }

    /**
     * Title of the teacher in charge of a class of this cycle.
     */
    public function headTeacherTitle(): string
    {
        return match ($this) {
            self::Nursery, self::Primary => 'Enseignant titulaire',
            self::Secondary => 'Professeur principal',
        };
    }

    /**
     * Whether one teacher usually teaches every subject of a class.
     */
    public function hasSingleTeacher(): bool
    {
        return $this !== self::Secondary;
    }

    public function section(): Section
    {
        return $this === self::Secondary ? Section::Secondary : Section::Basic;
    }

    /**
     * @return list<ClassLevel>
     */
    public function levels(): array
    {
        return array_values(array_filter(ClassLevel::cases(), fn (ClassLevel $level) => $level->cycle() === $this));
    }

    /**
     * Usual subjects of the Beninese curriculum for this cycle: code => [name, default coefficient].
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public function standardSubjects(): array
    {
        return match ($this) {
            self::Nursery => [
                'LANG' => ['Langage et communication', 1],
                'AMATH' => ['Activités mathématiques', 1],
                'EVEIL' => ['Éveil et découverte du milieu', 1],
                'GRAPH' => ['Graphisme et écriture', 1],
                'AART' => ['Activités artistiques', 1],
            ],
            self::Primary => [
                'FRP' => ['Français', 1],
                'MATHP' => ['Mathématiques', 1],
                'EST' => ['Éducation scientifique et technologique', 1],
                'ES' => ['Éducation sociale', 1],
                'EA' => ['Éducation artistique', 1],
                'EPSP' => ['Éducation physique et sportive', 1],
                'ANGP' => ['Anglais', 1],
            ],
            self::Secondary => [
                'FR' => ['Français', 3],
                'MATH' => ['Mathématiques', 3],
                'ANG' => ['Anglais', 2],
                'SVT' => ['Sciences de la vie et de la terre', 2],
                'PCT' => ['Physique-chimie-technologie', 2],
                'HG' => ['Histoire-géographie', 2],
                'EPS' => ['Éducation physique et sportive', 1],
            ],
        };
    }
}
