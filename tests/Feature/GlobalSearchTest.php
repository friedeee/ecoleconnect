<?php

namespace Tests\Feature;

use App\Enums\ClassLevel;
use App\Enums\Cycle;
use App\Enums\Role;
use App\Enums\Section;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->schoolAdmin();
        $this->class = SchoolClass::factory()->for($this->admin->school)->for($this->admin->school->currentAcademicYear)
            ->create(['name' => 'CM2 Étoile', 'level' => ClassLevel::Cm2]);
    }

    /**
     * @return list<string>
     */
    private function labels(User $user, string $query, string $group): array
    {
        $results = Livewire::actingAs($user)->test('global-search')->set('query', $query)->instance()->results;

        return array_column($results[$group]['items'] ?? [], 'label');
    }

    public function test_it_finds_a_student_by_name_or_matricule(): void
    {
        $student = Enrollment::factory()->create(['school_class_id' => $this->class->id])->student;
        $student->update(['last_name' => 'Agossou', 'first_name' => 'Rita', 'registration_number' => '2026-0777']);

        $this->assertSame(['AGOSSOU Rita'], $this->labels($this->admin, 'agoss', 'Élèves'));
        $this->assertSame(['AGOSSOU Rita'], $this->labels($this->admin, '0777', 'Élèves'));

        Livewire::actingAs($this->admin)->test('global-search')
            ->set('query', 'agoss')
            ->assertSee('2026-0777 · CM2 Étoile')
            ->assertSee(route('students.show', $student), escape: false);
    }

    public function test_it_finds_classes_subjects_and_staff(): void
    {
        Subject::factory()->for($this->admin->school)->create(['name' => 'Éducation artistique', 'code' => 'EA', 'cycle' => Cycle::Primary]);
        User::factory()->for($this->admin->school)->create(['name' => 'Mme Étoile HOUNSOU', 'role' => Role::Teacher]);

        $this->assertSame(['CM2 Étoile'], $this->labels($this->admin, 'étoile', 'Classes'));
        $this->assertSame(['Éducation artistique'], $this->labels($this->admin, 'artis', 'Matières'));
        $this->assertSame(['Mme Étoile HOUNSOU'], $this->labels($this->admin, 'hounsou', 'Personnel'));
    }

    public function test_it_finds_pages_without_accents(): void
    {
        $this->assertSame(['Élèves', 'Inscrire un élève'], $this->labels($this->admin, 'eleve', 'Pages'));
        $this->assertSame(['Paramètres'], $this->labels($this->admin, 'annee scolaire', 'Pages'));
    }

    public function test_a_teacher_only_finds_what_they_may_open(): void
    {
        $teacher = User::factory()->for($this->admin->school)->create(['role' => Role::Teacher]);
        Subject::factory()->for($this->admin->school)->create(['name' => 'Classes vertes', 'code' => 'CV']);

        $results = Livewire::actingAs($teacher)->test('global-search')->set('query', 'classe')->instance()->results;

        $this->assertSame(['Classes'], array_column($results['Pages']['items'], 'label'));
        $this->assertArrayNotHasKey('Matières', $results);
        $this->assertSame([], $this->labels($teacher, 'param', 'Pages'));
    }

    public function test_it_never_shows_another_school_or_section(): void
    {
        $otherAdmin = $this->schoolAdmin();
        SchoolClass::factory()->for($otherAdmin->school)->for($otherAdmin->school->currentAcademicYear)
            ->create(['name' => 'CM2 Lune', 'level' => ClassLevel::Cm2]);
        SchoolClass::factory()->for($this->admin->school)->for($this->admin->school->currentAcademicYear)
            ->create(['name' => '6e Lune', 'level' => ClassLevel::Sixth]);
        SchoolClass::factory()->for($this->admin->school)->for($this->admin->school->currentAcademicYear)
            ->create(['name' => 'CE1 Lune', 'level' => ClassLevel::Ce1]);

        $this->assertEqualsCanonicalizing(['6e Lune', 'CE1 Lune'], $this->labels($this->admin, 'lune', 'Classes'));

        $primaryDirector = User::factory()->admin()->for($this->admin->school)->inSection(Section::Basic)->create();
        $this->assertSame(['CE1 Lune'], $this->labels($primaryDirector, 'lune', 'Classes'));
    }

    public function test_a_single_character_searches_nothing(): void
    {
        Livewire::actingAs($this->admin)->test('global-search')
            ->set('query', 'c')
            ->assertSet('results', []);
    }

    public function test_the_search_bar_is_in_the_header(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee('Rechercher un élève, une classe, une page…');
    }
}
