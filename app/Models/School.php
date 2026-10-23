<?php

namespace App\Models;

use App\Enums\Cycle;
use App\Enums\PeriodType;
use App\Enums\Role;
use App\Enums\Section;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'city', 'address', 'phone', 'email', 'motto', 'logo_path', 'cycles', 'period_type', 'primary_scale', 'is_active'])]
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cycles' => AsEnumCollection::of(Cycle::class),
            'period_type' => PeriodType::class,
            'primary_scale' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Opens a new client school with its current academic year, the standard subjects
     * of the cycles it teaches and its first administrator.
     *
     * @param  array{name: string, city?: ?string, address?: ?string, phone?: ?string, email?: ?string, cycles: list<Cycle>}  $attributes
     */
    public static function open(array $attributes, string $adminName, string $adminEmail, string $adminPassword): self
    {
        return DB::transaction(function () use ($attributes, $adminName, $adminEmail, $adminPassword): self {
            $school = self::create([
                ...$attributes,
                'slug' => Str::slug($attributes['name']).'-'.Str::lower(Str::random(4)),
            ]);

            // The Beninese school year runs from mid-September to mid-July.
            $startYear = now()->month >= 8 ? now()->year : now()->year - 1;
            $school->academicYears()->create([
                'name' => $startYear.'-'.($startYear + 1),
                'starts_on' => $startYear.'-09-15',
                'ends_on' => ($startYear + 1).'-07-15',
                'is_current' => true,
            ]);

            foreach ($school->cycles as $cycle) {
                foreach ($cycle->standardSubjects() as $code => [$name]) {
                    $school->subjects()->create(['code' => $code, 'name' => $name, 'cycle' => $cycle]);
                }
            }

            $admin = new User(['name' => $adminName, 'email' => Str::lower($adminEmail), 'password' => $adminPassword]);
            $admin->forceFill(['school_id' => $school->id, 'role' => Role::Admin, 'must_change_password' => true])->save();

            return $school;
        });
    }

    /**
     * Whether the school teaches the given cycle.
     */
    public function teaches(Cycle $cycle): bool
    {
        return $this->cycles?->contains($cycle) ?? false;
    }

    /**
     * Scale marks and averages are shown on in a cycle: primary schools often mark out of 10.
     */
    public function scaleFor(Cycle $cycle): int
    {
        return $cycle === Cycle::Secondary ? 20 : $this->primary_scale;
    }

    /**
     * Whether the school teaches at least one cycle of the given section.
     */
    public function teachesSection(Section $section): bool
    {
        return collect($section->cycles())->contains(fn (Cycle $cycle) => $this->teaches($cycle));
    }

    /**
     * Sections taught by the school, in teaching order.
     *
     * @return list<Section>
     */
    public function sections(): array
    {
        return array_values(array_filter(Section::cases(), fn (Section $section) => $this->teachesSection($section)));
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<AcademicYear, $this>
     */
    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    /**
     * @return HasOne<AcademicYear, $this>
     */
    public function currentAcademicYear(): HasOne
    {
        return $this->hasOne(AcademicYear::class)->where('is_current', true);
    }

    /**
     * @return HasMany<Subject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /**
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
