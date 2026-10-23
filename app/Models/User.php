<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Enums\Section;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'section' => Section::class,
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The guardian profile linked to a parent account.
     *
     * @return HasOne<Guardian, $this>
     */
    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    /**
     * The section this user currently works in: their own one, or the one a whole-school
     * user chose to focus on. Null means the whole school.
     */
    public function activeSection(): ?Section
    {
        if ($this->section !== null) {
            return $this->section;
        }

        $chosen = Section::tryFrom((string) session('section'));

        return $chosen !== null && $this->school?->teachesSection($chosen) ? $chosen : null;
    }

    /**
     * Whether the user oversees the whole school rather than one section.
     */
    public function worksForWholeSchool(): bool
    {
        return $this->section === null;
    }

    /**
     * Staff who may work in the given section: its own staff and the whole-school staff.
     */
    #[Scope]
    protected function availableIn(Builder $query, ?Section $section): void
    {
        if ($section !== null) {
            $query->where(fn (Builder $query) => $query->where('section', $section)->orWhereNull('section'));
        }
    }

    /**
     * A phone number reduced to its national digits, so that "+229 01 97 00 00 01",
     * "0197000001" and "22901 97 00 00 01" all match.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) > 10 && str_starts_with($digits, '229') ? substr($digits, 3) : $digits;
    }

    /**
     * The children a parent account may follow: the students of its guardian profile.
     *
     * @return Collection<int, Student>
     */
    public function children(): Collection
    {
        if (! $this->hasRole(Role::Parent) || $this->guardian === null) {
            return new Collection;
        }

        return $this->guardian->students()
            ->with('currentEnrollment.schoolClass')
            ->orderBy('first_name')
            ->get();
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
