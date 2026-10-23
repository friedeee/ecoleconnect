<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_staff_page_renders_with_demo_data(): void
    {
        $admin = $this->schoolAdmin();
        $class = SchoolClass::factory()->create(['academic_year_id' => $admin->school->currentAcademicYear->id]);
        $student = Enrollment::factory()->create(['school_class_id' => $class->id])->student;
        $class->subjects()->attach(Subject::factory()->create(['school_id' => $admin->school_id]), ['coefficient' => 3]);

        $this->actingAs($admin);

        foreach ([
            route('dashboard'), route('students.index'), route('students.create'), route('students.show', $student),
            route('students.edit', $student), route('classes.index'), route('classes.show', $class),
            route('subjects.index'), route('staff.index'), route('settings'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_confirmations_use_the_application_dialog(): void
    {
        $admin = $this->schoolAdmin();
        AcademicYear::factory()->for($admin->school)->create(['name' => '2027-2028', 'is_current' => false]);

        $this->actingAs($admin)
            ->get(route('settings'))
            ->assertOk()
            ->assertSee('x-on:confirm-dialog.window', escape: false)
            ->assertSee('data-confirm-button="Activer"', escape: false);
    }

    public function test_the_first_academic_year_becomes_current(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test('pages::settings')
            ->set('year_name', '2026-2027')
            ->set('year_starts_on', '2026-09-14')
            ->set('year_ends_on', '2027-07-02')
            ->call('createYear')
            ->assertHasNoErrors();

        $this->assertTrue(AcademicYear::sole()->is_current);
    }

    public function test_activating_a_year_deactivates_the_previous_one(): void
    {
        $admin = $this->schoolAdmin();
        $previous = $admin->school->currentAcademicYear;
        $next = AcademicYear::factory()->for($admin->school)->create(['name' => '2027-2028', 'is_current' => false]);
        $otherSchoolYear = AcademicYear::factory()->create();

        Livewire::actingAs($admin)->test('pages::settings')->call('makeCurrent', $next->id);

        $this->assertFalse($previous->fresh()->is_current);
        $this->assertTrue($next->fresh()->is_current);
        $this->assertTrue($otherSchoolYear->fresh()->is_current);
    }

    public function test_an_admin_creates_a_staff_account_in_their_school(): void
    {
        $admin = $this->schoolAdmin();

        Livewire::actingAs($admin)->test('pages::staff.index')
            ->set('name', 'Jean KPADONOU')
            ->set('email', 'Jean@Example.com')
            ->set('role', Role::Accountant->value)
            ->call('create')
            ->assertHasNoErrors();

        $user = User::where('email', 'jean@example.com')->sole();
        $this->assertSame($admin->school_id, $user->school_id);
        $this->assertSame(Role::Accountant, $user->role);
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_staff_accounts_cannot_be_given_a_privileged_role(): void
    {
        Livewire::actingAs($this->schoolAdmin())->test('pages::staff.index')
            ->set('name', 'Intrus')
            ->set('email', 'intrus@example.com')
            ->set('role', Role::SuperAdmin->value)
            ->call('create')
            ->assertHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'intrus@example.com']);
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->schoolAdmin();

        Livewire::actingAs($admin)->test('pages::staff.index')
            ->call('toggleActive', $admin->id)
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_a_user_of_another_school(): void
    {
        $outsider = User::factory()->create();

        Livewire::actingAs($this->schoolAdmin())->test('pages::staff.index')
            ->call('toggleActive', $outsider->id)
            ->assertForbidden();

        $this->assertTrue($outsider->fresh()->is_active);
    }

    public function test_a_subject_used_by_a_class_cannot_be_deleted(): void
    {
        $admin = $this->schoolAdmin();
        $subject = Subject::factory()->create(['school_id' => $admin->school_id, 'name' => 'Français']);
        SchoolClass::factory()->create(['academic_year_id' => $admin->school->currentAcademicYear->id])
            ->subjects()->attach($subject, ['coefficient' => 3]);

        Livewire::actingAs($admin)->test('pages::subjects.index')
            ->call('delete', $subject->id)
            ->assertHasErrors('delete');

        $this->assertModelExists($subject);
    }

    public function test_an_admin_creates_a_class_and_assigns_a_subject_with_its_teacher(): void
    {
        $admin = $this->schoolAdmin();
        $teacher = User::factory()->for($admin->school)->create(['role' => Role::Teacher]);
        $subject = Subject::factory()->create(['school_id' => $admin->school_id]);

        Livewire::actingAs($admin)->test('pages::classes.index')
            ->set('level', '6e')
            ->set('name', '6e A')
            ->call('create')
            ->assertHasNoErrors();

        $class = SchoolClass::sole();

        Livewire::actingAs($admin)->test('pages::classes.show', ['schoolClass' => $class])
            ->set('subject_id', (string) $subject->id)
            ->set('coefficient', '4')
            ->set('teacher_id', (string) $teacher->id)
            ->call('addSubject')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('class_subject', [
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'coefficient' => 4,
        ]);
    }

    public function test_a_teacher_of_another_school_cannot_be_assigned(): void
    {
        $admin = $this->schoolAdmin();
        $class = SchoolClass::factory()->create(['academic_year_id' => $admin->school->currentAcademicYear->id]);
        $subject = Subject::factory()->create(['school_id' => $admin->school_id]);
        $outsider = User::factory()->create(['role' => Role::Teacher]);

        Livewire::actingAs($admin)->test('pages::classes.show', ['schoolClass' => $class])
            ->set('subject_id', (string) $subject->id)
            ->set('teacher_id', (string) $outsider->id)
            ->call('addSubject')
            ->assertHasErrors('teacher_id');
    }

    public function test_the_platform_owner_creates_a_school_with_its_administrator(): void
    {
        $this->artisan('school:create', [
            'name' => 'Collège Saint-Michel',
            '--admin-name' => 'Paul AHOUANDJINOU',
            '--admin-email' => 'Direction@SaintMichel.bj',
            '--city' => 'Cotonou',
            '--cycle' => ['primary', 'secondary'],
        ])->assertSuccessful();

        $school = School::sole();
        $admin = User::sole();
        $this->assertSame('Collège Saint-Michel', $school->name);
        $this->assertSame('direction@saintmichel.bj', $admin->email);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertSame($school->id, $admin->school_id);
    }

    public function test_school_creation_fails_for_an_existing_email(): void
    {
        $existing = User::factory()->create();

        $this->artisan('school:create', [
            'name' => 'Doublon',
            '--admin-name' => 'X',
            '--admin-email' => $existing->email,
            '--cycle' => ['primary'],
        ])->assertFailed();

        $this->assertSame(1, School::count());
    }
}
