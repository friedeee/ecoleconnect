<?php

namespace Tests\Feature;

use App\Enums\AssessmentKind;
use App\Enums\ClassLevel;
use App\Enums\Role;
use App\Enums\Section;
use App\Http\Middleware\EnsureParentAccess;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\EnsureSchoolAccess;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\GradingPeriod;
use App\Models\Guardian;
use App\Models\Mark;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private SchoolClass $class;

    private Subject $maths;

    private Assessment $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-16 10:00');

        $this->admin = $this->schoolAdmin();
        $school = $this->admin->school;
        $this->teacher = User::factory()->for($school)->create(['role' => Role::Teacher, 'name' => 'M. Koffi ADANDE']);
        $this->class = SchoolClass::factory()->for($school)->for($school->currentAcademicYear)->create(['name' => '6e A', 'level' => ClassLevel::Sixth]);
        $this->maths = Subject::factory()->for($school)->create(['name' => 'Mathématiques']);
        $this->class->subjects()->attach($this->maths->id, ['coefficient' => 3, 'teacher_id' => $this->teacher->id]);

        $this->quiz = Assessment::factory()->create([
            'school_class_id' => $this->class->id,
            'subject_id' => $this->maths->id,
            'grading_period_id' => GradingPeriod::ensureFor($school->currentAcademicYear)->first()->id,
            'kind' => AssessmentKind::Quiz,
            'held_on' => '2026-10-12',
        ]);
    }

    private function sheet(User $user): Testable
    {
        return Livewire::actingAs($user)->test('pages::grades.sheet', ['schoolClass' => $this->class, 'subject' => $this->maths]);
    }

    public function test_livewire_actions_go_through_the_same_checks_as_pages(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        foreach ([EnsurePasswordChanged::class, EnsureSchoolAccess::class, EnsureParentAccess::class, EnsurePlatformAccess::class] as $middleware) {
            $this->assertContains($middleware, $persistent);
        }
    }

    public function test_a_teacher_deactivated_while_a_page_is_open_can_no_longer_save_marks(): void
    {
        $student = Enrollment::factory()->create(['school_class_id' => $this->class->id])->student;
        $sheet = $this->sheet($this->teacher);

        $this->teacher->update(['is_active' => false]);

        $sheet->set("scores.{$this->quiz->id}.{$student->id}", '18')->call('save')->assertForbidden();
        $this->assertSame(0, Mark::count());
    }

    public function test_the_staff_of_a_suspended_school_can_no_longer_collect_money(): void
    {
        $accountant = User::factory()->accountant()->for($this->admin->school)->create();
        $enrollment = Enrollment::factory()->create(['school_class_id' => $this->class->id]);
        $fees = Livewire::actingAs($accountant)->test('student-fees', ['enrollment' => $enrollment]);

        $this->admin->school->update(['is_active' => false]);
        $accountant->unsetRelation('school');

        $fees->call('openForm')->assertForbidden();
    }

    public function test_an_account_is_locked_after_ten_failures_whatever_the_network(): void
    {
        foreach (range(1, 10) as $attempt) {
            RateLimiter::hit('login-account:'.$this->admin->email, 15 * 60);
        }

        Livewire::test('pages::auth.login')
            ->set('email', $this->admin->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_parent_temporary_passwords_mix_letters_and_digits(): void
    {
        $passwords = collect(range(1, 50))->map(fn () => Guardian::readablePassword());

        $passwords->each(fn (string $password) => $this->assertMatchesRegularExpression('/^[a-hjkmnp-z2-9]{4}-[a-hjkmnp-z2-9]{4}$/', $password));
        $this->assertGreaterThan(45, $passwords->unique()->count());
    }

    public function test_mark_changes_are_logged_with_their_old_and_new_values(): void
    {
        $student = Enrollment::factory()->create(['school_class_id' => $this->class->id])->student;
        Mark::factory()->create(['assessment_id' => $this->quiz->id, 'student_id' => $student->id, 'score' => 9]);

        $this->sheet($this->teacher)->set("scores.{$this->quiz->id}.{$student->id}", '15,5')->call('save')->assertHasNoErrors();

        $entry = ActivityLog::where('action', 'marks.updated')->sole();
        $this->assertSame($this->teacher->id, $entry->user_id);
        $this->assertSame('M. Koffi ADANDE', $entry->user_name);
        $this->assertStringContainsString('Mathématiques · 6e A · Int. 1 du 12/10/2026 : 1 note(s)', $entry->description);
        $this->assertSame([['student' => $student->full_name, 'old' => '9', 'new' => '15,5']], $entry->properties['changes']);

        // Saving again without any change writes nothing.
        $this->sheet($this->teacher)->call('save');
        $this->assertSame(1, ActivityLog::where('action', 'marks.updated')->count());
    }

    public function test_payments_and_their_cancellation_are_logged(): void
    {
        $accountant = User::factory()->accountant()->for($this->admin->school)->create();
        $enrollment = Enrollment::factory()->create(['school_class_id' => $this->class->id]);

        $fees = Livewire::actingAs($accountant)
            ->test('student-fees', ['enrollment' => $enrollment])
            ->call('openForm')
            ->set(['amount' => '25000', 'method' => 'cash'])
            ->call('record');

        $payment = Payment::sole();
        $fees->call('startCancelling', $payment->id)->set('cancellation_reason', 'Erreur de montant')->call('cancelPayment');

        $this->assertSame(['payment.recorded', 'payment.cancelled'], ActivityLog::orderBy('id')->where('action', 'like', 'payment.%')->pluck('action')->all());
        $this->assertStringContainsString('25 000 F CFA annulé', ActivityLog::where('action', 'payment.cancelled')->sole()->description);
    }

    public function test_only_the_principal_reads_the_activity_log_of_their_own_school(): void
    {
        ActivityLog::record('settings.school', 'Informations de l\'école modifiées', user: $this->admin);
        $other = $this->schoolAdmin();
        ActivityLog::record('settings.school', 'Secret d\'une autre école', user: $other);

        $this->actingAs($this->admin)->get(route('activity'))->assertOk()->assertSee('Informations de l&#039;école modifiées', escape: false)->assertDontSee('Secret d');

        $director = User::factory()->admin()->for($this->admin->school)->inSection(Section::Basic)->create();
        $this->actingAs($director)->get(route('activity'))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('activity'))->assertForbidden();
    }

    public function test_logins_are_logged(): void
    {
        Livewire::test('pages::auth.login')->set('email', $this->admin->email)->set('password', 'password')->call('login');

        $this->assertSame($this->admin->id, ActivityLog::where('action', 'auth.login')->sole()->user_id);
    }

    public function test_the_backup_dumps_the_database_and_files_then_prunes_old_ones(): void
    {
        Process::fake();
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);
        $old = $directory.'/base-2000-01-01_020000.dump';
        File::put($old, 'ancienne');
        touch($old, now()->subDays(30)->getTimestamp());

        $this->artisan('backup:run')->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command[0] === 'pg_dump' && in_array('--format=custom', $process->command, true));
        $this->assertFileDoesNotExist($old);
    }

    public function test_a_failed_backup_is_reported(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'connection refused', exitCode: 1)]);

        $this->artisan('backup:run')->expectsOutputToContain('connection refused')->assertFailed();
    }
}
