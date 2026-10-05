<?php

namespace App\Models;

use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Security\Authorizer;
use App\Security\SecurityLevel;
use App\Support\Settings;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;

/**
 * Not tenant-scoped by a global scope (auth resolves users before a tenant is known);
 * every query listing users filters by tenant explicitly.
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, InteractsWithPasskeys, Notifiable;

    protected $fillable = ['name', 'username', 'email', 'password', 'security_level', 'is_active'];

    protected $hidden = ['password', 'remember_token', 'pin_hash', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'security_level' => 'integer',
            'is_active' => 'boolean',
            'pin_locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function level(): SecurityLevel
    {
        return SecurityLevel::from($this->security_level);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // L0 external users get portals later, never the back office.
        return $this->is_active && $this->security_level >= SecurityLevel::Restricted->value;
    }

    public function requiresMfa(): bool
    {
        return $this->security_level >= (int) app(Settings::class)->get('security.mfa_from_level', $this->tenant_id);
    }

    public function hasPermission(string $permission, mixed $target = null): bool
    {
        return app(Authorizer::class)->allows($this, $permission, $target);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    /** Turning two-factor on or off is a security change: audited with the save. */
    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        app(Recorder::class)->save(Actor::user($this, 'web'), $this, $secret ? 'auth.mfa_enabled' : 'auth.mfa_disabled');
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        app(Recorder::class)->save(Actor::user($this, 'web'), $this, 'auth.mfa_recovery_codes_changed');
    }
}
