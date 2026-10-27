<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToSchool;
use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['first_name', 'last_name', 'phone', 'whatsapp', 'email', 'profession'])]
class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use BelongsToSchool, HasFactory;

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => mb_strtoupper($this->last_name).' '.$this->first_name);
    }

    /**
     * Opens, or reopens, this guardian's parent portal account and returns its new temporary password.
     * The parent signs in with their phone number and must choose their own password first.
     */
    public function openPortalAccount(): string
    {
        $password = self::readablePassword();

        $user = $this->user ?? new User;
        $user->fill(['name' => trim($this->first_name.' '.mb_strtoupper($this->last_name)), 'phone' => User::normalizePhone($this->phone), 'password' => $password, 'is_active' => true]);
        $user->forceFill(['school_id' => $this->school_id, 'role' => Role::Parent, 'must_change_password' => true])->save();

        $this->user()->associate($user)->save();

        return $password;
    }

    /**
     * A temporary password a parent can copy from paper without mistakes: "k7mp-4x2q".
     * Letters and digits that look alike (0/o, 1/l/i) are left out.
     */
    public static function readablePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $characters = array_map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, 8));

        return implode('', array_slice($characters, 0, 4)).'-'.implode('', array_slice($characters, 4));
    }

    /**
     * The portal account of this guardian, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Student, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class)
            ->withPivot(['relationship', 'is_primary_contact'])
            ->withTimestamps();
    }
}
