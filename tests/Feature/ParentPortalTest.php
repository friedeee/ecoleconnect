<?php

namespace Tests\Feature;

use App\Enums\AssessmentKind;
use App\Enums\ClassLevel;
use App\Enums\Cycle;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FeeItem;
use App\Models\GradingPeriod;
use App\Models\Guardian;
use App\Models\Homework;
use App\Models\Mark;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    private Student $child;

    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-16 10:00');

        $this->admin = $this->schoolAdmin();
        $school = $this->admin->school;
        $this->class = SchoolClass::factory()->for($school)->for($school->currentAcademicYear)->create(['name' => 'CM2', 'level' => ClassLevel::Cm2]);
        $this->child = Enrollment::factory()->create([
            'school_class_id' => $this->class->id,
            'student_id' => Student::factory()->for($school)->create(['first_name' => 'Fifamè', 'last_name' => 'Houngbo']),
        ])->student;

        $this->guardian = Guardian::factory()->for($school)->create(['first_name' => 'Bertin', 'last_name' => 'Houngbo', 'phone' => '+22901 97 11 22 33']);
        $this->child->guardians()->attach($this->guardian->id, ['relationship' => 'father', 'is_primary_contact' => true]);
    }

    private function parent(): User
    {
        $this->guardian->openPortalAccount();
        $user = $this->guardian->fresh()->user;
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    public function test_the_school_opens_a_parent_access_shown_once(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test('pages::students.show', ['student' => $this->child])
            ->call('openParentAccess', $this->guardian->id)
            ->assertSee('Accès parent prêt pour HOUNGBO Bertin');

        $user = $this->guardian->fresh()->user;
        $this->assertSame(Role::Parent, $user->role);
        $this->assertSame('0197112233', $user->phone);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->email);
        $this->assertMatchesRegularExpression('/^[a-z2-9]{4}-[a-z2-9]{4}$/', $component->get('credentials')['password']);
    }

    public function test_a_parent_signs_in_with_their_phone_and_must_choose_a_password(): void
    {
        $password = $this->guardian->openPortalAccount();

        Livewire::test('pages::auth.login')
            ->set('audience', 'parent')
            ->set('email', '01 97 11 22 33')
            ->set('password', $password)
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($this->guardian->fresh()->user);
        $this->get(route('parent.home'))->assertRedirect(route('password.edit'));

        Livewire::test('pages::auth.password')
            ->set('password', 'Fifame2026')
            ->set('password_confirmation', 'Fifame2026')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('parent.home'));
        $this->get(route('parent.home'))->assertOk()->assertSee('Bonjour Bertin')->assertSee('Fifamè HOUNGBO');
    }

    public function test_the_phone_matches_whatever_its_format(): void
    {
        $password = $this->guardian->openPortalAccount();

        foreach (['+229 01 97 11 22 33', '0197112233', '229 0197112233'] as $phone) {
            Livewire::test('pages::auth.login')->set('audience', 'parent')->set('email', $phone)->set('password', $password)->call('login')->assertHasNoErrors();
            auth()->logout();
        }

        Livewire::test('pages::auth.login')->set('audience', 'parent')->set('email', '0197112233')->set('password', 'mauvais')->call('login')->assertHasErrors('email');
    }

    public function test_each_login_tab_only_accepts_its_own_accounts(): void
    {
        $password = $this->guardian->openPortalAccount();

        Livewire::test('pages::auth.login')
            ->set('email', '0197112233')
            ->set('password', $password)
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Choisissez l&#039;onglet « Parent d&#039;élève »', escape: false);

        Livewire::test('pages::auth.login')
            ->set('audience', 'parent')
            ->set('email', $this->admin->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email')
            ->assertSee('Choisissez l&#039;onglet « Personnel »', escape: false);

        $this->assertGuest();
    }

    public function test_a_parent_only_sees_their_own_children(): void
    {
        $stranger = Enrollment::factory()->create(['school_class_id' => $this->class->id])->student;

        $this->actingAs($this->parent())->get(route('parent.child', $this->child))->assertOk();
        $this->get(route('parent.child', $stranger))->assertNotFound();
        $this->get(route('students.show', $this->child))->assertRedirect(route('parent.home'));
    }

    public function test_report_cards_appear_once_the_period_is_locked(): void
    {
        $period = GradingPeriod::ensureFor($this->admin->school->currentAcademicYear)->first();
        $subject = Subject::factory()->for($this->admin->school)->create(['name' => 'Calcul', 'cycle' => Cycle::Primary]);
        $this->class->subjects()->attach($subject->id, ['coefficient' => 1]);
        $assessment = Assessment::factory()->create(['school_class_id' => $this->class->id, 'subject_id' => $subject->id, 'grading_period_id' => $period->id, 'kind' => AssessmentKind::Evaluation, 'out_of' => 10]);
        Mark::factory()->create(['assessment_id' => $assessment->id, 'student_id' => $this->child->id, 'score' => 8]);

        $this->actingAs($this->parent())->get(route('parent.child', $this->child))->assertSee('Aucun bulletin pour l');
        $this->get(route('parent.report-card', [$this->child, $period]))->assertNotFound();

        $period->forceFill(['locked_at' => now()])->save();

        $this->get(route('parent.child', $this->child))->assertSee('1er trimestre')->assertSee('8,00');
        $this->assertSame('application/pdf', $this->get(route('parent.report-card', [$this->child, $period]))->assertOk()->headers->get('Content-Type'));
    }

    public function test_a_parent_follows_fees_and_downloads_receipts(): void
    {
        $year = $this->admin->school->currentAcademicYear;
        FeeItem::factory()->for($year)->create(['school_id' => $year->school_id, 'level' => ClassLevel::Cm2, 'label' => '1re tranche', 'amount' => 30000, 'due_on' => '2026-10-01']);
        $payment = Payment::factory()->create(['enrollment_id' => Enrollment::where('student_id', $this->child->id)->value('id'), 'amount' => 10000]);
        $otherPayment = Payment::factory()->create(['enrollment_id' => Enrollment::factory()->create(['school_class_id' => $this->class->id])->id]);

        $this->actingAs($this->parent());

        Livewire::test('pages::parent.child', ['student' => $this->child])
            ->set('tab', 'frais')
            ->assertSee('1re tranche')
            ->assertSee('En retard')
            ->assertSee('Reçu '.$payment->receipt_number);

        $this->get(route('parent.receipt', $payment))->assertOk();
        $this->get(route('parent.receipt', $otherPayment))->assertNotFound();
    }

    public function test_a_parent_sees_absences_timetable_and_exercises(): void
    {
        Storage::fake('local');
        $subject = Subject::factory()->for($this->admin->school)->create(['name' => 'Lecture']);
        Absence::factory()->create(['enrollment_id' => Enrollment::where('student_id', $this->child->id)->value('id'), 'date' => '2026-10-14']);
        TimetableEntry::factory()->create(['school_class_id' => $this->class->id, 'subject_id' => $subject->id, 'day' => 2, 'starts_at' => '08:00', 'ends_at' => '10:00']);
        $homework = Homework::factory()->create([
            'school_class_id' => $this->class->id, 'subject_id' => $subject->id, 'title' => 'Lire la page 12',
            'attachment_path' => UploadedFile::fake()->create('fiche.pdf', 10, 'application/pdf')->store('homework', 'local'), 'attachment_name' => 'fiche.pdf',
        ]);

        $this->actingAs($this->parent());
        $component = Livewire::test('pages::parent.child', ['student' => $this->child]);

        $component->set('tab', 'absences')->assertSee('Non justifiée');
        $component->set('tab', 'emploi-du-temps')->assertSee('Mardi')->assertSee('08:00 – 10:00');
        $component->set('tab', 'exercices')->assertSee('Lire la page 12')->assertSee('fiche.pdf');

        $this->get(route('parent.homework-file', $homework))->assertOk()->assertDownload('fiche.pdf');
    }

    public function test_a_teacher_sets_an_exercise_with_a_worksheet(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->for($this->admin->school)->create(['role' => Role::Teacher]);
        $subject = Subject::factory()->for($this->admin->school)->create(['name' => 'Lecture']);
        $this->class->subjects()->attach($subject->id, ['coefficient' => 1, 'teacher_id' => $teacher->id]);

        Livewire::actingAs($teacher)
            ->test('pages::homework')
            ->call('openForm')
            ->set('assignment', $this->class->id.'-'.$subject->id)
            ->set('title', 'Exercices 3 à 7 page 42')
            ->set('due_on', '2026-10-19')
            ->set('attachment', UploadedFile::fake()->create('fiche.pdf', 200, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Exercices 3 à 7 page 42');

        $homework = Homework::sole();
        $this->assertSame($teacher->id, $homework->created_by);
        Storage::disk('local')->assertExists($homework->attachment_path);
    }

    public function test_a_teacher_only_sets_exercises_in_their_subjects(): void
    {
        $teacher = User::factory()->for($this->admin->school)->create(['role' => Role::Teacher]);
        $subject = Subject::factory()->for($this->admin->school)->create();
        $this->class->subjects()->attach($subject->id, ['coefficient' => 1]);

        Livewire::actingAs($teacher)
            ->test('pages::homework')
            ->set('assignment', $this->class->id.'-'.$subject->id)
            ->set('title', 'Intrus')
            ->set('given_on', '2026-10-16')
            ->call('save')
            ->assertHasErrors(['assignment']);
    }

    public function test_the_school_opens_every_parent_access_of_a_class(): void
    {
        $other = Enrollment::factory()->create(['school_class_id' => $this->class->id])->student;
        $other->guardians()->attach(Guardian::factory()->for($this->admin->school)->create()->id, ['relationship' => 'mother', 'is_primary_contact' => true]);

        $component = Livewire::actingAs($this->admin)
            ->test('pages::classes.show', ['schoolClass' => $this->class])
            ->call('openParentAccesses');

        $this->assertCount(2, $component->get('parentAccesses'));
        $this->assertSame(2, User::where('role', Role::Parent)->count());

        $component->call('openParentAccesses')->assertSet('parentAccesses', []);
        $this->assertSame(2, User::where('role', Role::Parent)->count());
    }

    public function test_new_staff_accounts_must_change_their_temporary_password(): void
    {
        Livewire::actingAs($this->admin)
            ->test('pages::staff.index')
            ->set(['name' => 'Mme Aïda', 'email' => 'aida@ecole.bj', 'role' => 'teacher'])
            ->call('create');

        $teacher = User::where('email', 'aida@ecole.bj')->sole();
        $this->assertTrue($teacher->must_change_password);
        $this->actingAs($teacher)->get(route('dashboard'))->assertRedirect(route('password.edit'));
    }

    public function test_a_suspended_school_closes_the_parent_portal(): void
    {
        $parent = $this->parent();
        $this->admin->school->update(['is_active' => false]);

        $this->actingAs($parent)->get(route('parent.home'))->assertRedirect(route('login'));
    }
}
