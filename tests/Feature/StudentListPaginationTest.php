<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentListPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->schoolAdmin();
        $this->actingAs($this->admin);
        Student::factory()->for($this->admin->school)->count(60)->create();
    }

    public function test_the_list_shows_the_range_and_total_in_french(): void
    {
        Livewire::test('pages::students.index')
            ->assertSee('1–25')
            ->assertSee('sur')
            ->assertSee('60')
            ->assertSee('Page suivante')
            ->assertDontSee('Showing');
    }

    public function test_the_user_moves_to_the_next_page(): void
    {
        Livewire::test('pages::students.index')
            ->call('nextPage')
            ->assertSee('26–50');
    }

    public function test_changing_the_page_size_goes_back_to_the_first_page(): void
    {
        Livewire::test('pages::students.index')
            ->call('gotoPage', 3)
            ->set('perPage', 50)
            ->assertSee('1–50');
    }

    public function test_the_list_is_filtered_by_gender(): void
    {
        Student::factory()->for($this->admin->school)->create(['gender' => Gender::Female, 'last_name' => 'Tchibozo', 'first_name' => 'Aïcha']);
        Student::factory()->for($this->admin->school)->create(['gender' => Gender::Male, 'last_name' => 'Tchibozo', 'first_name' => 'Koffi']);
        $girls = Student::where('gender', Gender::Female)->count();

        Livewire::test('pages::students.index')
            ->set('gender', 'F')
            ->set('search', 'Tchibozo')
            ->assertSee('Aïcha')
            ->assertDontSee('Koffi');

        Livewire::test('pages::students.index')
            ->set('gender', 'F')
            ->assertSee($girls.' élève(s)');
    }

    public function test_an_unknown_gender_is_ignored(): void
    {
        Livewire::withQueryParams(['sexe' => 'X'])
            ->test('pages::students.index')
            ->assertSee('60 élève(s)');
    }

    public function test_an_unknown_page_size_falls_back_to_the_default(): void
    {
        Livewire::withQueryParams(['par_page' => 5000])
            ->test('pages::students.index')
            ->assertSee('1–25');
    }
}
