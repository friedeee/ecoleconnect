<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\ClassLevel;
use App\Enums\DaySession;
use App\Enums\Role;
use App\Enums\Section;
use App\Models\Absence;
use App\Models\Enrollment;
use App\Models\GradingPeriod;
use App\Models\RollCall;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private SchoolClass $class;

    /** @var list<Student> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-16 09:30');

        $this->admin = $this->schoolAdmin();
        $school = $this->admin->school;
        $this->teacher = User::factory()->for($school)->create(['role' => Role::Teacher]);
        $this->class = SchoolClass::factory()->for($school)->for($school->currentAcademicYear)
            ->create(['name' => 'CE2', 'level' => ClassLevel::Ce2, 'head_teacher_id' => $this->teacher->id]);

        $this->students = collect(['Akpo', 'Bossou', 'Chabi'])
            ->map(fn (string $name) => Enrollment::factory()->create([
                'school_class_id' => $this->class->id,
                'student_id' => Student::factory()->for($school)->create(['last_name' => $name]),
            ])->student)
            ->all();
    }

    private function rollCall(User $user, array $statuses, array $params = []): Testable
    {
        $component = Livewire::actingAs($user)
            ->withQueryParams($params)
            ->test('pages::attendance.roll-call', ['schoolClass' => $this->class]);

        foreach ($statuses as $index => $status) {
            $component->set('statuses.'.$this->students[$index]->id, $status);
        }

        return $component->call('save');
    }

    public function test_the_class_teacher_calls_the_roll(): void
    {
        $this->rollCall($this->teacher, ['present', 'absent', 'late'])
            ->assertHasNoErrors()
            ->assertSee('Appel enregistré.');

        $rollCall = RollCall::sole();
        $this->assertSame(DaySession::Morning, $rollCall->session);
        $this->assertSame($this->teacher->id, $rollCall->taken_by);

        $this->assertSame(
            [$this->students[1]->id => AttendanceStatus::Absent, $this->students[2]->id => AttendanceStatus::Late],
            Absence::orderBy('student_id')->get()->mapWithKeys(fn (Absence $absence) => [$absence->student_id => $absence->status])->all(),
        );
    }

    public function test_correcting_a_roll_call_keeps_justifications(): void
    {
        $this->rollCall($this->teacher, ['absent', 'absent', 'present']);
        Absence::where('student_id', $this->students[0]->id)->update(['is_justified' => true, 'reason' => 'Maladie']);

        $component = $this->rollCall($this->teacher, ['absent', 'present', 'present']);

        $this->assertSame(1, RollCall::count());
        $this->assertSame([$this->students[0]->id], Absence::pluck('student_id')->all());
        $this->assertTrue(Absence::sole()->is_justified);
        $component->assertSet('statuses.'.$this->students[0]->id, 'absent');
    }

    public function test_a_teacher_of_another_class_cannot_call_the_roll(): void
    {
        $outsider = User::factory()->for($this->admin->school)->create(['role' => Role::Teacher]);

        $this->actingAs($outsider)->get(route('attendance.roll-call', $this->class))->assertForbidden();
        $this->get(route('attendance.index'))->assertOk()->assertDontSee('CE2');
    }

    public function test_teachers_only_correct_the_last_seven_days(): void
    {
        $this->rollCall($this->teacher, ['absent'], ['date' => '2026-10-01'])->assertForbidden();
        $this->rollCall($this->admin, ['absent'], ['date' => '2026-10-01'])->assertHasNoErrors();
        $this->rollCall($this->teacher, ['absent'], ['date' => '2026-10-12', 'seance' => 'afternoon'])->assertHasNoErrors();

        $this->assertSame(['2026-10-01', '2026-10-12'], Absence::orderBy('date')->get()->map(fn (Absence $absence) => $absence->date->toDateString())->all());
    }

    public function test_a_roll_call_cannot_be_in_the_future(): void
    {
        $this->rollCall($this->admin, ['absent'], ['date' => '2026-10-20']);

        $this->assertSame('2026-10-16', Absence::sole()->date->toDateString());
    }

    public function test_the_day_view_shows_which_rolls_were_called(): void
    {
        $this->rollCall($this->teacher, ['absent', 'late', 'present']);
        SchoolClass::factory()->for($this->admin->school)->for($this->admin->school->currentAcademicYear)->create(['name' => 'CM1', 'level' => ClassLevel::Cm1]);

        Livewire::actingAs($this->admin)
            ->test('pages::attendance.index')
            ->assertSee('Appel fait dans')
            ->assertSee('1 absent(s)')
            ->assertSee('1 retard(s)')
            ->assertSee('À faire');
    }

    public function test_an_admin_justifies_an_absence(): void
    {
        $this->rollCall($this->teacher, ['absent']);
        $absence = Absence::sole();
        $enrollment = Enrollment::where('student_id', $this->students[0]->id)->sole();

        Livewire::actingAs($this->teacher)
            ->test('student-attendance', ['enrollment' => $enrollment])
            ->call('startJustifying', $absence->id)
            ->assertForbidden();

        Livewire::actingAs($this->admin)
            ->test('student-attendance', ['enrollment' => $enrollment])
            ->call('startJustifying', $absence->id)
            ->call('justify')
            ->assertHasErrors(['reason' => 'required'])
            ->set('reason', 'Paludisme, certificat médical')
            ->call('justify')
            ->assertHasNoErrors()
            ->assertSee('Justifiée');

        $absence->refresh();
        $this->assertTrue($absence->is_justified);
        $this->assertSame($this->admin->id, $absence->justified_by);
    }

    public function test_the_summary_flags_students_with_many_unjustified_absences(): void
    {
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $day) {
            Absence::factory()->create(['enrollment_id' => Enrollment::where('student_id', $this->students[0]->id)->value('id'), 'date' => $day]);
        }
        Absence::factory()->create(['enrollment_id' => Enrollment::where('student_id', $this->students[1]->id)->value('id'), 'status' => AttendanceStatus::Late]);

        $component = Livewire::actingAs($this->admin)->test('pages::attendance.index')->set('tab', 'recap');

        $this->assertSame([$this->students[0]->id, $this->students[1]->id], $component->instance()->summary->pluck('student_id')->all());
        $component->assertSee('À signaler aux parents');

        Livewire::actingAs($this->teacher)->test('pages::attendance.index', ['tab' => 'recap'])->assertSet('tab', 'appel');
    }

    public function test_absences_appear_on_the_report_card(): void
    {
        $enrollmentId = Enrollment::where('student_id', $this->students[0]->id)->value('id');
        Absence::factory()->create(['enrollment_id' => $enrollmentId, 'date' => '2026-10-12', 'is_justified' => true]);
        Absence::factory()->create(['enrollment_id' => $enrollmentId, 'date' => '2026-10-13']);
        Absence::factory()->create(['enrollment_id' => $enrollmentId, 'date' => '2026-10-14', 'status' => AttendanceStatus::Late]);

        $period = GradingPeriod::ensureFor($this->admin->school->currentAcademicYear)->first();

        $this->actingAs($this->admin)
            ->get(route('grades.report-cards', [$this->class, $period, 'eleve' => $this->students[0]->id]))
            ->assertOk()
            ->assertSeeText('Absences : 2 demi-journée(s) dont 1 justifiée(s) · Retards : 1');
    }

    public function test_a_section_director_does_not_see_the_other_sections_absences(): void
    {
        $this->rollCall($this->teacher, ['absent']);
        $director = User::factory()->admin()->for($this->admin->school)->inSection(Section::Secondary)->create();

        $this->actingAs($director)->get(route('attendance.roll-call', $this->class))->assertNotFound();
        $this->assertSame(0, Absence::count());
    }
}
