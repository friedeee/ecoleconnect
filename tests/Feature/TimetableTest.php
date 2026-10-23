<?php

namespace Tests\Feature;

use App\Enums\ClassLevel;
use App\Enums\Role;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class TimetableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private SchoolClass $sixth;

    private SchoolClass $fifth;

    private Subject $maths;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->schoolAdmin();
        $school = $this->admin->school;
        $this->teacher = User::factory()->for($school)->create(['role' => Role::Teacher, 'name' => 'M. Koffi ADANDE']);
        $this->maths = Subject::factory()->for($school)->create(['name' => 'Mathématiques']);

        $this->sixth = SchoolClass::factory()->for($school)->for($school->currentAcademicYear)->create(['name' => '6e A', 'level' => ClassLevel::Sixth]);
        $this->fifth = SchoolClass::factory()->for($school)->for($school->currentAcademicYear)->create(['name' => '5e A', 'level' => ClassLevel::Fifth]);
        $this->sixth->subjects()->attach($this->maths->id, ['coefficient' => 3, 'teacher_id' => $this->teacher->id]);
        $this->fifth->subjects()->attach($this->maths->id, ['coefficient' => 3, 'teacher_id' => $this->teacher->id]);
    }

    private function addLesson(SchoolClass $class, array $fields): Testable
    {
        return Livewire::actingAs($this->admin)
            ->test('pages::timetable', ['classId' => (string) $class->id])
            ->set('classId', (string) $class->id)
            ->set(array_merge(['day' => '1', 'starts_at' => '08:00', 'ends_at' => '10:00', 'subject_id' => (string) $this->maths->id, 'room' => ''], $fields))
            ->call('add');
    }

    public function test_an_admin_adds_a_lesson_with_its_teacher(): void
    {
        $this->addLesson($this->sixth, ['room' => 'Salle 3'])->assertHasNoErrors()->assertSee('M. Koffi ADANDE')->assertSee('Salle 3');

        $entry = TimetableEntry::sole();
        $this->assertSame($this->teacher->id, $entry->teacher_id);
        $this->assertSame(120, $entry->durationInMinutes());
    }

    public function test_a_class_cannot_have_two_lessons_at_once(): void
    {
        $this->addLesson($this->sixth, []);
        $this->addLesson($this->sixth, ['starts_at' => '09:00', 'ends_at' => '11:00'])->assertHasErrors('conflicts')->assertSee('La classe a déjà Mathématiques de 08:00–10:00.');

        $this->addLesson($this->sixth, ['starts_at' => '10:00', 'ends_at' => '11:00'])->assertHasNoErrors();
        $this->assertSame(2, TimetableEntry::count());
    }

    public function test_a_teacher_cannot_be_in_two_classes_at_once(): void
    {
        $this->addLesson($this->sixth, []);

        $this->addLesson($this->fifth, ['starts_at' => '09:30', 'ends_at' => '10:30'])
            ->assertHasErrors('conflicts')
            ->assertSee('L&#039;enseignant est déjà en 6e A', escape: false);

        $this->addLesson($this->fifth, ['day' => '2'])->assertHasNoErrors();
    }

    public function test_a_room_cannot_host_two_classes_at_once(): void
    {
        $other = Subject::factory()->for($this->admin->school)->create(['name' => 'Anglais']);
        $this->fifth->subjects()->attach($other->id, ['coefficient' => 2]);

        $this->addLesson($this->sixth, ['room' => 'Labo']);
        $this->addLesson($this->fifth, ['subject_id' => (string) $other->id, 'room' => 'labo'])->assertHasErrors('conflicts')->assertSee('La salle labo est déjà occupée');
    }

    public function test_the_end_must_follow_the_start(): void
    {
        $this->addLesson($this->sixth, ['starts_at' => '10:00', 'ends_at' => '09:00'])->assertHasErrors(['ends_at']);
    }

    public function test_a_teacher_sees_their_own_week_across_classes(): void
    {
        $this->addLesson($this->sixth, []);
        $this->addLesson($this->fifth, ['day' => '3']);

        $this->actingAs($this->teacher)
            ->get(route('timetable'))
            ->assertOk()
            ->assertSee('Semaine de M. Koffi ADANDE')
            ->assertSee('6e A')
            ->assertSee('5e A')
            ->assertDontSee('Ajouter un cours');

        Livewire::actingAs($this->teacher)->test('pages::timetable')->call('delete', TimetableEntry::first()->id)->assertForbidden();
    }

    public function test_the_weekly_hours_are_totalled(): void
    {
        $this->addLesson($this->sixth, []);
        $this->addLesson($this->sixth, ['day' => '4', 'starts_at' => '15:00', 'ends_at' => '16:30']);

        Livewire::actingAs($this->admin)->test('pages::timetable', ['classId' => (string) $this->sixth->id])
            ->set('classId', (string) $this->sixth->id)
            ->assertSee('3,5 h')
            ->assertSee('Mathématiques 3,5 h');
    }

    public function test_accountants_have_no_timetable(): void
    {
        $this->actingAs(User::factory()->accountant()->for($this->admin->school)->create())->get(route('timetable'))->assertForbidden();
    }
}
