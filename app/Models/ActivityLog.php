<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry of a school's activity log: who changed marks, collected money, opened an account…
 * The author's name is copied so that the log stays readable after an account is deleted.
 */
#[Fillable(['action', 'description', 'properties'])]
class ActivityLog extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    /**
     * Families of actions, to filter the log: action prefix => label.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'marks' => 'Notes',
        'assessment' => 'Évaluations',
        'report-cards' => 'Bulletins',
        'payment' => 'Paiements',
        'fees' => 'Barème des frais',
        'attendance' => 'Absences',
        'student' => 'Élèves',
        'staff' => 'Personnel',
        'parent-access' => 'Accès parents',
        'settings' => 'Paramètres',
        'auth' => 'Connexions et mots de passe',
        'platform' => 'Plateforme',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Writes an entry for the signed-in user, e.g. ActivityLog::record('payment.cancelled', 'Paiement 2026-00012 annulé', $payment).
     *
     * @param  array<string, mixed>  $properties  details shown when the entry is opened, such as old and new values
     */
    public static function record(string $action, string $description, ?Model $subject = null, array $properties = [], ?User $user = null): self
    {
        $user ??= auth()->user();

        $entry = new self(['action' => $action, 'description' => mb_substr($description, 0, 500), 'properties' => $properties ?: null]);
        $entry->forceFill([
            'school_id' => $subject?->getAttribute('school_id') ?? $user?->school_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => request()->ip(),
        ])->save();

        return $entry;
    }

    public function category(): string
    {
        return self::CATEGORIES[explode('.', $this->action)[0]] ?? 'Autre';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
