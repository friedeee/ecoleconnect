<?php

namespace App\Models\Concerns;

use App\Enums\Section;
use App\Models\Scopes\SectionScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hides the records of the other section from users who work in one section only.
 */
trait RestrictedToSection
{
    public static function bootRestrictedToSection(): void
    {
        static::addGlobalScope(new SectionScope);
    }

    /**
     * Limits the query to the records of the given section.
     *
     * @param  Builder<static>  $query
     */
    abstract public function restrictToSection(Builder $query, Section $section): void;
}
