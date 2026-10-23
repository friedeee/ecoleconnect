<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: Role, 1: string, 2: bool}>
     */
    public static function permissionMatrix(): array
    {
        return [
            'admin manages the school' => [Role::Admin, 'manage-school', true],
            'admin manages students' => [Role::Admin, 'manage-students', true],
            'admin views students' => [Role::Admin, 'view-students', true],
            'teacher cannot manage the school' => [Role::Teacher, 'manage-school', false],
            'teacher cannot manage students' => [Role::Teacher, 'manage-students', false],
            'teacher views students' => [Role::Teacher, 'view-students', true],
            'accountant cannot manage the school' => [Role::Accountant, 'manage-school', false],
            'accountant cannot manage students' => [Role::Accountant, 'manage-students', false],
            'accountant views students' => [Role::Accountant, 'view-students', true],
            'parent cannot view students' => [Role::Parent, 'view-students', false],
        ];
    }

    #[DataProvider('permissionMatrix')]
    public function test_role_permissions(Role $role, string $ability, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($user)->allows($ability));
    }

    public function test_teachers_cannot_open_the_enrollment_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Teacher]))
            ->get(route('students.create'))
            ->assertForbidden();
    }

    public function test_accountants_cannot_open_the_settings(): void
    {
        $this->actingAs(User::factory()->accountant()->create())
            ->get(route('settings'))
            ->assertForbidden();
    }

    public function test_teachers_can_browse_students_and_classes(): void
    {
        $teacher = User::factory()->for($this->schoolAdmin()->school)->create(['role' => Role::Teacher]);

        $this->actingAs($teacher)->get(route('students.index'))->assertOk()->assertDontSee('Inscrire un élève');
        $this->actingAs($teacher)->get(route('classes.index'))->assertOk();
    }
}
