<?php

namespace App\Providers;

use App\Enums\Role;
use App\Http\Middleware\EnsureParentAccess;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\EnsureSchoolAccess;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureGates();

        // Livewire actions (saving marks, collecting a payment…) are separate requests: they must go
        // through the same checks as the page, so that a deactivated account or a suspended school
        // cannot keep acting from a page left open.
        Livewire::addPersistentMiddleware([
            EnsurePasswordChanged::class,
            EnsureSchoolAccess::class,
            EnsureParentAccess::class,
            EnsurePlatformAccess::class,
        ]);
    }

    /**
     * Define what each school role may do.
     */
    protected function configureGates(): void
    {
        // A deactivated account, or the staff of a suspended school, may do nothing at all.
        Gate::before(function (User $user): ?bool {
            if (! $user->is_active || ($user->school_id !== null && ! $user->school?->is_active)) {
                return false;
            }

            return null;
        });

        Gate::define('manage-platform', fn (User $user): bool => $user->is_active && $user->hasRole(Role::SuperAdmin));

        Gate::define('manage-school', fn (User $user): bool => $user->hasRole(Role::Admin));

        Gate::define('manage-whole-school', fn (User $user): bool => $user->hasRole(Role::Admin) && $user->worksForWholeSchool());

        Gate::define('manage-students', fn (User $user): bool => $user->hasRole(Role::Admin));

        Gate::define('view-students', fn (User $user): bool => $user->hasRole(Role::Admin, Role::Teacher, Role::Accountant));

        Gate::define('view-grades', fn (User $user): bool => $user->hasRole(Role::Admin, Role::Teacher));

        // Admins grade every class they can see; a teacher grades the subjects they teach, and a
        // nursery or primary class's own teacher grades all of its subjects.
        Gate::define('grade', fn (User $user, SchoolClass $class, Subject $subject): bool => $user->hasRole(Role::Admin)
            || ($user->hasRole(Role::Teacher) && (
                $class->subjects()->whereKey($subject->id)->wherePivot('teacher_id', $user->id)->exists()
                || ($class->cycle->hasSingleTeacher() && $class->head_teacher_id === $user->id)
            )));

        Gate::define('manage-report-cards', fn (User $user): bool => $user->hasRole(Role::Admin));

        Gate::define('view-attendance', fn (User $user): bool => $user->hasRole(Role::Admin, Role::Teacher));

        // Admins and the class's own teachers call the roll.
        Gate::define('take-attendance', fn (User $user, SchoolClass $class): bool => $user->hasRole(Role::Admin)
            || ($user->hasRole(Role::Teacher) && (
                $class->head_teacher_id === $user->id
                || $class->subjects()->wherePivot('teacher_id', $user->id)->exists()
            )));

        Gate::define('justify-absences', fn (User $user): bool => $user->hasRole(Role::Admin));

        Gate::define('view-timetable', fn (User $user): bool => $user->hasRole(Role::Admin, Role::Teacher));

        Gate::define('manage-timetable', fn (User $user): bool => $user->hasRole(Role::Admin));

        Gate::define('view-fees', fn (User $user): bool => $user->hasRole(Role::Admin, Role::Accountant));

        // Section directors follow their section's fees but only the cashiers handle money.
        Gate::define('collect-payments', fn (User $user): bool => $user->hasRole(Role::Accountant)
            || ($user->hasRole(Role::Admin) && $user->worksForWholeSchool()));

        Gate::define('manage-fee-schedule', fn (User $user): bool => Gate::forUser($user)->allows('collect-payments'));
    }
}
