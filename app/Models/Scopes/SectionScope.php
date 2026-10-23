<?php

namespace App\Models\Scopes;

use App\Models\Concerns\RestrictedToSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Restricts every query to the section (nursery-primary or secondary) the authenticated user works in.
 */
class SectionScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     * @param  Model&RestrictedToSection  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $section = Auth::user()?->activeSection();

        if ($section !== null) {
            $model->restrictToSection($builder, $section);
        }
    }
}
