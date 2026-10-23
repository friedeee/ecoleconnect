<?php

namespace Tests\Feature;

use App\Enums\AssessmentKind;
use App\Enums\ClassLevel;
use App\Enums\Cycle;
use App\Enums\Mention;
use App\Enums\PeriodType;
use App\Enums\Role;
use App\Enums\Section;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\GradingPeriod;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GradesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $mathsTeacher;

    private SchoolClass $class;

    private Subject $maths;

    private Subject $french;

    private GradingPeriod $period;

    /** @var list<Student> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-16 10:00');

        $this->admin = $this->schoolAdmin();
        $school = $this->admin->school;
        $year = $school->currentAcademicYear;

        $this->mathsTeacher = User::factory()->for($school)->create(['role' => Role::Teacher]);
        $this->class = SchoolClass::factory()->for($school)->for($year)->create(['name' => '6e A', 'level' => ClassLevel::Sixth]);
        $this->maths = Subject::factory()->for($school)->create(['name' => 'Mathématiques', 'code' => 'MATH', 'cycle' => Cycle::Secondary]);
        $this->french = Subject::factory()->for($school)->create(['name' => 'Français', 'code' => 'FR', 'cycle' => Cycle::Secondary]);
        $this->class->subjects()->attach([
            $this->maths->id => ['coefficient' => 3, 'teacher_id' => $this->mathsTeacher->id],
            $this->french->id => ['coefficient' => 2, 'teacher_id' => null],
        ]);

        $this->students = collect(['Adjovi', 'Bello', 'Codjo'])
            ->map(fn (string $name) => Enrollment::factory()->create([
                'school_class_id' => $this->class->id,
                'student_id' => Student::factory()->for($school)->create(['last_name' => $name]),
            ])->student)
            ->all();

        $this->period = GradingPeriod::ensureFor($year)->first();
    }

    /**
     * @param  array<int, float|string|null>  $scores  one per student, "abs" for absent
     */
    private function assessment(Subject $subject, AssessmentKind $kind, array $scores, int $outOf = 20): Assessment
    {
        $assessment = Assessment::factory()->create([
            'school_class_id' => $this->class->id,
            'grading_period_id' => $this->period->id,
            'subject_id' => $subject->id,
            'kind' => $kind,
            'out_of' => $outOf,
        ]);

        foreach ($scores as $index => $score) {
            if ($score !== null) {
                Mark::factory()->create([
                    'assessment_id' => $assessment->id,
                    'student_id' => $this->students[$index]->id,
                    'score' => $score === 'abs' ? null : $score,
                    'is_absent' => $score === 'abs',
                ]);
            }
        }

        return $assessment;
    }

    public function test_the_year_is_split_into_the_schools_periods(): void
    {
        $periods = GradingPeriod::ensureFor($this->admin->school->currentAcademicYear);

        $this->assertSame(['1er trimestre', '2e trimestre', '3e trimestre'], $periods->pluck('name')->all());
        $this->assertSame('2026-09-14', $periods->first()->starts_on->toDateString());
        $this->assertSame('2027-07-02', $periods->last()->ends_on->toDateString());
        $this->assertTrue($periods->first()->is(GradingPeriod::covering($periods)));
    }

    public function test_secondary_averages_follow_the_beninese_formula(): void
    {
        $this->assessment($this->maths, AssessmentKind::Quiz, [12, 'abs']);
        $this->assessment($this->maths, AssessmentKind::Quiz, [14, 8]);
        $this->assessment($this->maths, AssessmentKind::Test, [10, 12]);
        $this->assessment($this->maths, AssessmentKind::Test, [8, null], outOf: 10);

        $assessments = Assessment::with('marks')->get();

        // (moyenne des interrogations 13 + devoir 10 + devoir 16) / 3
        $this->assertSame(13.0, Assessment::averageFor($assessments, $this->students[0]->id, Cycle::Secondary));
        // The absence and the missing devoir do not count: (8 + 12) / 2
        $this->assertSame(10.0, Assessment::averageFor($assessments, $this->students[1]->id, Cycle::Secondary));
        $this->assertNull(Assessment::averageFor($assessments, $this->students[2]->id, Cycle::Secondary));
    }

    public function test_class_results_weight_subjects_and_rank_students(): void
    {
        $this->actingAs($this->admin);
        $this->assessment($this->maths, AssessmentKind::Test, [16, 10, 10]);
        $this->assessment($this->french, AssessmentKind::Test, [11, 13, 13]);

        $results = $this->period->classResults($this->class);
        $rows = collect($results['students'])->keyBy(fn (array $row) => $row['student']->last_name);

        $this->assertSame(14.0, $rows['Adjovi']['general']); // (16 × 3 + 11 × 2) / 5
        $this->assertSame(11.2, $rows['Bello']['general']);
        $this->assertSame(1, $rows['Adjovi']['rank']);
        $this->assertSame(2, $rows['Bello']['rank']);
        $this->assertSame(2, $rows['Codjo']['rank']); // ex aequo
        $this->assertSame(Mention::Good, $rows['Adjovi']['mention']);
        $this->assertSame(12.13, $results['class_average']);
        $this->assertSame(14.0, $results['highest']);
        $this->assertSame(3, $results['graded']);
    }

    public function test_a_teacher_only_grades_their_own_subjects(): void
    {
        $this->actingAs($this->mathsTeacher);

        $this->get(route('grades.sheet', [$this->class, $this->maths]))->assertOk();
        $this->get(route('grades.sheet', [$this->class, $this->french]))->assertForbidden();

        $this->get(route('grades.index'))->assertSee('Mathématiques')->assertDontSee('Français');
        $this->get(route('grades.reports'))->assertForbidden();
    }

    public function test_a_primary_class_teacher_grades_every_subject_of_their_class(): void
    {
        $titular = User::factory()->for($this->admin->school)->create(['role' => Role::Teacher]);
        $primary = SchoolClass::factory()->for($this->admin->school)->for($this->admin->school->currentAcademicYear)
            ->create(['name' => 'CM1', 'level' => ClassLevel::Cm1, 'head_teacher_id' => $titular->id]);
        $reading = Subject::factory()->for($this->admin->school)->create(['cycle' => Cycle::Primary]);
        $primary->subjects()->attach($reading->id, ['coefficient' => 1]);

        $this->actingAs($titular)->get(route('grades.sheet', [$primary, $reading]))->assertOk()->assertSee('Moyennes sur 10');
    }

    public function test_marks_are_entered_on_the_sheet(): void
    {
        $quiz = $this->assessment($this->maths, AssessmentKind::Quiz, [5, null, null]);

        Livewire::actingAs($this->mathsTeacher)
            ->test('pages::grades.sheet', ['schoolClass' => $this->class, 'subject' => $this->maths])
            ->assertSet("scores.{$quiz->id}.{$this->students[0]->id}", '5')
            ->set("scores.{$quiz->id}.{$this->students[0]->id}", '')
            ->set("scores.{$quiz->id}.{$this->students[1]->id}", '12,5')
            ->set("scores.{$quiz->id}.{$this->students[2]->id}", 'ABS')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Notes enregistrées.');

        $this->assertDatabaseMissing('marks', ['student_id' => $this->students[0]->id]);
        $this->assertSame(12.5, Mark::where('student_id', $this->students[1]->id)->sole()->score);
        $this->assertTrue(Mark::where('student_id', $this->students[2]->id)->sole()->is_absent);
    }

    public function test_an_invalid_mark_blocks_the_whole_save(): void
    {
        $quiz = $this->assessment($this->maths, AssessmentKind::Quiz, [], outOf: 10);

        Livewire::actingAs($this->mathsTeacher)
            ->test('pages::grades.sheet', ['schoolClass' => $this->class, 'subject' => $this->maths])
            ->set("scores.{$quiz->id}.{$this->students[0]->id}", '9')
            ->set("scores.{$quiz->id}.{$this->students[1]->id}", '14')
            ->call('save')
            ->assertHasErrors(["scores.{$quiz->id}.{$this->students[1]->id}"]);

        $this->assertSame(0, Mark::count());
    }

    public function test_a_teacher_adds_an_assessment_of_their_cycle(): void
    {
        $component = Livewire::actingAs($this->mathsTeacher)
            ->test('pages::grades.sheet', ['schoolClass' => $this->class, 'subject' => $this->maths])
            ->call('openForm')
            ->set('kind', 'evaluation')
            ->call('addAssessment')
            ->assertHasErrors(['kind']);

        $component->set('kind', 'test')->call('addAssessment')->assertHasNoErrors();

        $assessment = Assessment::sole();
        $this->assertSame(AssessmentKind::Test, $assessment->kind);
        $this->assertTrue($assessment->gradingPeriod->is($this->period));
        $this->assertSame($this->mathsTeacher->id, $assessment->created_by);
    }

    public function test_a_period_holds_at_most_three_interrogations_and_two_devoirs(): void
    {
        foreach (range(1, 3) as $i) {
            $this->assessment($this->maths, AssessmentKind::Quiz, []);
        }
        $this->assessment($this->maths, AssessmentKind::Test, []);

        $component = Livewire::actingAs($this->mathsTeacher)
            ->test('pages::grades.sheet', ['schoolClass' => $this->class, 'subject' => $this->maths])
            ->assertSee('Interrogations : 3/3')
            ->assertSee('Devoirs : 1/2')
            ->call('openForm')
            ->assertSet('kind', 'test')
            ->set('kind', 'quiz')
            ->call('addAssessment')
            ->assertHasErrors(['kind' => 'in'])
            ->assertSee('Maximum atteint : 3 interrogations par période.');

        $component->set('kind', 'test')->call('addAssessment')->assertHasNoErrors();

        $this->assertSame(5, Assessment::count());
        $component->assertDontSee('Nouvelle évaluation')->call('openForm')->assertForbidden();
    }

    public function test_the_limit_is_counted_per_subject_and_period(): void
    {
        foreach (range(1, 3) as $i) {
            $this->assessment($this->maths, AssessmentKind::Quiz, []);
        }

        Livewire::actingAs($this->admin)
            ->test('pages::grades.sheet', ['schoolClass' => $this->class, 'subject' => $this->french])
            ->call('openForm')
            ->set('kind', 'quiz')
            ->call('addAssessment')
            ->assertHasNoErrors();
    }

    public function test_a_primary_class_holds_at_most_three_evaluations(): void
    {
        $primary = SchoolClass::factory()->for($this->admin->school)->for($this->admin->school->currentAcademicYear)
            ->create(['name' => 'CM1', 'level' => ClassLevel::Cm1]);
        $reading = Subject::factory()->for($this->admin->school)->create(['cycle' => Cycle::Primary]);
        $primary->subjects()->attach($reading->id, ['coefficient' => 1]);
        Assessment::factory()->count(3)->create([
            'school_class_id' => $primary->id, 'subject_id' => $reading->id,
            'grading_period_id' => $this->period->id, 'kind' => AssessmentKind::Evaluation, 'out_of' => 10,
        ]);

        Livewire::actingAs($this->admin)
            ->test('pages::grades.sheet', ['schoolClass' => $primary, 'subject' => $reading])
            ->assertSee('Évaluations : 3/3')
            ->assertDontSee('Nouvelle évaluation')
            ->set('kind', 'evaluation')
            ->call('addAssessment')
            ->assertHasErrors(['kind' => 'in']);

        $this->assertSame(3, Assessment::where('school_class_id', $primary->id)->count());
    }

    public function test_a_locked_period_cannot_be_changed(): void
    {
        $quiz = $this->assessment($this->maths, AssessmentKind::Quiz, []);

        Livewire::actingAs($this->admin)->test('pages::grades.reports')->call('toggleLock');
        $this->assertTrue($this->period->fresh()->isLocked());

        Livewire::actingAs($this->mathsTeacher)
            ->test('pages::grades.sheet', ['schoolClass' => $this->class, 'subject' => $this->maths])
            ->assertSee('Période verrouillée')
            ->set("scores.{$quiz->id}.{$this->students[0]->id}", '12')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Mark::count());
    }

    public function test_only_the_principal_locks_a_period(): void
    {
        $director = User::factory()->admin()->for($this->admin->school)->inSection(Section::Secondary)->create();

        Livewire::actingAs($director)->test('pages::grades.reports')->call('toggleLock')->assertForbidden();
        $this->assertFalse($this->period->fresh()->isLocked());
    }

    public function test_report_cards_print_and_download(): void
    {
        $this->assessment($this->maths, AssessmentKind::Test, [16, 10, 10]);
        $this->assessment($this->french, AssessmentKind::Test, [11, 13, 13]);

        $this->actingAs($this->admin)
            ->get(route('grades.report-cards', [$this->class, $this->period]))
            ->assertOk()
            ->assertSeeText('BULLETIN DE NOTES — 1ER TRIMESTRE')
            ->assertSee('ADJOVI')
            ->assertSee('CODJO')
            ->assertSee('14,00 / 20')
            ->assertSee('2e ex');

        $this->get(route('grades.report-cards', [$this->class, $this->period, 'eleve' => $this->students[0]->id]))
            ->assertOk()
            ->assertSee('ADJOVI')
            ->assertDontSee('CODJO');

        $pdf = $this->get(route('grades.report-cards.pdf', [$this->class, $this->period]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_a_period_of_another_year_is_not_found(): void
    {
        $otherYear = AcademicYear::factory()->for($this->admin->school)->create(['name' => '2025-2026', 'is_current' => false]);
        $otherPeriod = GradingPeriod::ensureFor($otherYear)->first();

        $this->actingAs($this->admin)->get(route('grades.report-cards', [$this->class, $otherPeriod]))->assertNotFound();
    }

    public function test_the_school_switches_to_semesters_until_marks_are_given(): void
    {
        Livewire::actingAs($this->admin)
            ->test('pages::settings')
            ->set('period_type', 'semester')
            ->call('saveGrading')
            ->assertHasNoErrors();

        $periods = GradingPeriod::ensureFor($this->admin->school->currentAcademicYear->fresh());
        $this->assertSame(['1er semestre', '2e semestre'], $periods->pluck('name')->all());
        $this->assertSame(PeriodType::Semester, $this->admin->school->fresh()->period_type);

        $this->period = $periods->first();
        $this->assessment($this->maths, AssessmentKind::Test, [12]);

        Livewire::actingAs($this->admin)
            ->test('pages::settings')
            ->set('period_type', 'trimester')
            ->call('saveGrading')
            ->assertHasErrors(['period_type']);
    }
}
