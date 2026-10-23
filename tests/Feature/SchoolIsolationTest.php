<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_of_another_school_is_not_found(): void
    {
        $otherStudent = Student::factory()->create();

        $this->actingAs($this->schoolAdmin())
            ->get(route('students.show', $otherStudent))
            ->assertNotFound();
    }

    public function test_a_class_of_another_school_is_not_found(): void
    {
        $otherClass = SchoolClass::factory()->create();

        $this->actingAs($this->schoolAdmin())
            ->get(route('classes.show', $otherClass))
            ->assertNotFound();
    }

    public function test_the_student_list_only_shows_students_of_the_users_school(): void
    {
        $admin = $this->schoolAdmin();
        $ownClass = SchoolClass::factory()->create(['academic_year_id' => $admin->school->currentAcademicYear->id]);
        $ownStudent = Enrollment::factory()->create(['school_class_id' => $ownClass->id])->student;
        $otherStudent = Enrollment::factory()->create()->student;

        $this->actingAs($admin)
            ->get(route('students.index'))
            ->assertOk()
            ->assertSee($ownStudent->registration_number)
            ->assertDontSee($otherStudent->registration_number);
    }

    public function test_a_subject_of_another_school_cannot_be_edited(): void
    {
        $otherSubject = Subject::factory()->create();

        $this->actingAs($this->schoolAdmin());

        Livewire::test('pages::subjects.index')
            ->call('edit', $otherSubject->id)
            ->assertNotFound();
    }

    public function test_records_created_by_a_user_belong_to_their_school(): void
    {
        $admin = $this->schoolAdmin();

        $this->actingAs($admin);
        $subject = Subject::create(['name' => 'Philosophie', 'code' => 'PHILO']);

        $this->assertSame($admin->school_id, $subject->school_id);
    }
}
