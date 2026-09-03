<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['tenant_id', 'employee_id', 'name', 'email', 'password', 'phone', 'telegram_chat_id', 'role', 'status', 'last_login_at', 'two_factor_enabled', 'two_factor_secret', 'two_factor_recovery_codes', 'avatar_path'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_OPERATOR = 'operator';
    // ТЗ раздел 21 — специалист видит только свой график/клиентов (see
    // BookingPolicy, requires employee_id to actually be set); бухгалтер видит
    // платежи/возвраты/финансовые отчёты (see BookingPolicy::managePayments()).
    public const ROLE_SPECIALIST = 'specialist';
    public const ROLE_ACCOUNTANT = 'accountant';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_OWNER,
        self::ROLE_MANAGER,
        self::ROLE_OPERATOR,
        self::ROLE_SPECIALIST,
        self::ROLE_ACCOUNTANT,
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

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
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'password' => 'hashed',
        ];
    }
}
