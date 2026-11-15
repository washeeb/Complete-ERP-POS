<?php

namespace App\Filament\Pages;

use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Filament\Support\Erp;
use App\Models\User;
use App\Security\Reconfirm;
use App\Security\SignInCode;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * "Confirm it's you" before money leaves (App\Security\Reconfirm): the authenticator code, a passkey, a code sent by
 * WhatsApp or e-mail, or (for someone with none of these) the password. Valid for Reconfirm::MINUTES. Opened from the
 * note a money action shows, in a new tab, so the form on the first tab keeps what was typed.
 */
class ConfirmItsYou extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'confirm-its-you';

    protected static ?string $title = "Confirm it's you";

    protected string $view = 'filament.pages.confirm-its-you';

    public string $code = '';

    public string $password = '';

    public ?string $sentTo = null;

    public bool $done = false;

    public static function canAccess(): bool
    {
        return Erp::user() !== null;
    }

    public function mount(): void
    {
        session()->put('url.intended', static::getUrl(['passkey' => 1])); // a passkey sign-in comes back here
        $this->done = Reconfirm::recent() && request()->boolean('passkey');
    }

    public function hasApp(): bool
    {
        return filled(Erp::user()->app_authentication_secret);
    }

    public function hasPasskey(): bool
    {
        return Erp::user()->passkeys()->exists();
    }

    public function sendCode(): void
    {
        $this->sentTo = app(SignInCode::class)->send(Erp::user()) ?? 'nowhere: try again in a few minutes';
    }

    public function confirm(): void
    {
        $user = Erp::user();
        $key = "reconfirm:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            Notification::make()->danger()->title('Too many tries')->body('Wait a minute, then try again.')->send();

            return;
        }
        $code = preg_replace('/\s+/', '', $this->code);
        $ok = ($code !== '' && ($this->hasApp() && app(AppAuthentication::class)->verifyCode($code, $user->getAppAuthenticationSecret())
                || $this->sentTo && app(SignInCode::class)->verify($user, $code)))
            || (! $this->hasApp() && ! $this->hasPasskey() && $this->password !== '' && Hash::check($this->password, $user->password));
        if (! $ok) {
            RateLimiter::hit($key, 60);
            Notification::make()->danger()->title('That did not match')->body('Check the code (or password) and try again.')->send();

            return;
        }
        RateLimiter::clear($key);
        self::confirmed($user, $code !== '' ? 'code' : 'password');
        $this->reset('code', 'password');
        $this->done = true;
    }

    public static function confirmed(User $user, string $how): void
    {
        Reconfirm::mark();
        DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($user, 'web'), 'auth.reconfirmed', $user, [], 'Confirmed it is them before a payment ('.Str::lower($how).')'));
    }
}
