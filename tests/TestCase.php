<?php

namespace Tests;

use App\Models\AcademicYear;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates a school with a current academic year and returns its administrator.
     */
    protected function schoolAdmin(?School $school = null): User
    {
        $school ??= School::factory()->create();

        if (! $school->academicYears()->exists()) {
            AcademicYear::factory()->for($school)->create();
        }

        return User::factory()->admin()->for($school)->create();
    }
}
