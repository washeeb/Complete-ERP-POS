<?php

namespace App\Models;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Models\Concerns\HasOriginNode;
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
    use HasApiTokens, HasFactory, HasOriginNode, HasUuids, InteractsWithPasskeys, Notifiable;

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
        if ($panel->getId() === 'vendor') { // SyncLytics' licence generator: the licence server only
            return VendorLicensing::allowed($this);
        }

        // L0 external users get portals later, never the back office.
        return $this->is_active && $this->security_level >= SecurityLevel::Restricted->value;
    }

    /** How a person may sign in, chosen by the administrator (owner request 2026-10-22). */
    public const SIGNIN_METHODS = [
        'default' => 'Company rule (System → Settings → Security)',
        'app' => 'Password and the authenticator app',
        'code' => 'Password and a code by WhatsApp or e-mail',
        'password' => 'Password only',
    ];

    /** Password only is for low-risk staff: up to supervisors. Higher levels approve money and access. */
    public const PASSWORD_ONLY_MAX_LEVEL = 3;

    public function isSupport(): bool
    {
        return $this->security_level >= SecurityLevel::Platform->value;
    }

    /** A second step at sign-in is needed: always for SyncLytics support; else as the administrator chose; else the company rule. */
    public function requiresMfa(): bool
    {
        if ($this->isSupport()) {
            return true;
        }

        $companyRule = $this->security_level >= (int) app(Settings::class)->get('security.mfa_from_level', $this->tenant_id);

        return match ($this->signin_method) {
            'app', 'code' => true,
            'password' => $this->security_level > self::PASSWORD_ONLY_MAX_LEVEL && $companyRule, // promoted since: the company rule again
            default => $companyRule,
        };
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
        // Securing one's own sign-in is allowed even while the installation is read-only (not yet activated): the
        // owner must set up two-step sign-in before reaching the licence page. It is still audited.
        LicenseManager::bypass(fn () => app(Recorder::class)->save(Actor::user($this, 'web'), $this, $secret ? 'auth.mfa_enabled' : 'auth.mfa_disabled'));
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
        LicenseManager::bypass(fn () => app(Recorder::class)->save(Actor::user($this, 'web'), $this, 'auth.mfa_recovery_codes_changed'));
    }
}
