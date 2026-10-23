<?php

namespace App\Enums;

enum AssessmentKind: string
{
    case Quiz = 'quiz';
    case Test = 'test';
    case Evaluation = 'evaluation';

    public function label(): string
    {
        return match ($this) {
            self::Quiz => 'Interrogation',
            self::Test => 'Devoir',
            self::Evaluation => 'Évaluation',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Quiz => 'Int.',
            self::Test => 'Dev.',
            self::Evaluation => 'Éval.',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Quiz => 'interrogations',
            self::Test => 'devoirs',
            self::Evaluation => 'évaluations',
        };
    }

    /**
     * Most assessments of this kind a class may have in one subject and one period:
     * at most 3 interrogations and 2 devoirs in the secondary cycle, 3 evaluations elsewhere.
     */
    public function maxPerPeriod(): int
    {
        return match ($this) {
            self::Quiz => 3,
            self::Test => 2,
            self::Evaluation => 3,
        };
    }

    /**
     * Kinds of assessment used in the given cycle.
     *
     * @return list<self>
     */
    public static function for(Cycle $cycle): array
    {
        return $cycle === Cycle::Secondary ? [self::Quiz, self::Test] : [self::Evaluation];
    }
}
