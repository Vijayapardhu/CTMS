<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Enum-cast attributes, declared so static analysis reads the cast rather
 * than the raw column type. Without these, a comparison against the enum
 * looks to the analyser like a comparison against a string — which is the
 * exact defect class this codebase started with.
 *
 * @property UserRole $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /**
     * Mass assignable attributes.
     *
     * SECURITY: `role` and `is_active` are deliberately absent. Privilege is
     * never taken from a request payload — it is set explicitly by the
     * registration/administration services after an authorization check.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'phone_number',
        'password',
        'first_name',
        'last_name',
        'gender',
        'date_of_birth',
        'address',
        'city',
        'state',
        'postal_code',
        'profile_picture',
    ];

    /**
     * Attributes hidden from every serialized representation.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'email_verified' => 'boolean',
            'phone_verified' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    // ========================================================================
    // RELATIONSHIPS
    // ========================================================================

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function admin(): HasOne
    {
        return $this->hasOne(Admin::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function notificationDevices(): HasMany
    {
        return $this->hasMany(NotificationDevice::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * The role-specific profile row for this user, whichever it is.
     */
    public function profile(): ?Model
    {
        return match ($this->role) {
            UserRole::ADMIN => $this->admin,
            UserRole::DRIVER => $this->driver,
            UserRole::STUDENT => $this->student,
            default => null,
        };
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRole(Builder $query, UserRole $role): Builder
    {
        return $query->where('role', $role->value);
    }

    // ========================================================================
    // ROLE CHECKS
    // ========================================================================

    /**
     * Whether the user holds the given role.
     *
     * Compares enum to enum — never raw strings, so a casing mismatch cannot
     * silently grant or deny access.
     */
    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Whether the user holds any of the given roles.
     */
    public function hasAnyRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::ADMIN);
    }

    public function isDriver(): bool
    {
        return $this->hasRole(UserRole::DRIVER);
    }

    public function isStudent(): bool
    {
        return $this->hasRole(UserRole::STUDENT);
    }

    /**
     * The administrator tier this user holds, or null if they are not an
     * administrator.
     *
     * A missing `admins` row deliberately returns null rather than defaulting
     * to a level: an ADMIN account with no profile is a broken account, and
     * treating it as a VIEWER would silently grant it read access to
     * everything a viewer can see.
     */
    public function accessLevel(): ?AccessLevel
    {
        if (! $this->isAdmin()) {
            return null;
        }

        $stored = $this->admin?->access_level;

        return $stored === null ? null : AccessLevel::tryFrom((string) $stored);
    }

    /**
     * Whether this user holds at least the given administrator tier.
     */
    public function hasAccessLevel(AccessLevel $required): bool
    {
        return $this->accessLevel()?->atLeast($required) === true;
    }

    /**
     * BR-512 — the identity background jobs act under.
     *
     * Resolved once per request. It is a real, auditable user that can never
     * log in: `is_active` is false, so authentication rejects it outright.
     */
    public static function systemActor(): ?self
    {
        static $cached = null;

        if ($cached !== null && static::find($cached->getKey()) !== null) {
            return $cached;
        }

        return $cached = static::where('is_system', true)->first();
    }

    /**
     * Real people, excluding the scheduler's own identity. Anything that
     * counts, lists or notifies administrators must use this — the system
     * actor is not a colleague and has no inbox.
     */
    public function scopeHuman(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    // Note: object-level "is this the same person?" checks use Eloquent's
    // inherited Model::is(), which is already null-safe and compares the key,
    // table and connection.

    // ========================================================================
    // HELPERS
    // ========================================================================

    public function getFullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function updateLoginAt(): void
    {
        $this->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
