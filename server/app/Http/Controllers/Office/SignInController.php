<?php

namespace App\Http\Controllers\Office;

use App\Domain\Branding\Branding;
use App\Filament\Support\PanelBrand;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\SignInCode;
use App\Support\UserPrefs;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Signing in to the new back office (part 41), step for step as the current sign-in (Filament's Login, App\Filament\Auth
 * \Login): the e-mail or the username; five tries a minute; every failure takes the same time (Timebox), whether the
 * person exists, the password is wrong or the account may not sign in; then, for someone with two-factor sign-in, the
 * challenge — the code from the app or a recovery code, or a code sent by WhatsApp or e-mail (sent before the challenge),
 * with its own limit — and the password and access checked again before the session starts (a change made meanwhile
 * counts). Signing in fires the usual events, so the audit trail, the new-computer warning and the support notice stay.
 */
class SignInController extends Controller
{
    private const PENDING = 'office.signin.pending';

    private const PENDING_MINUTES = 10;

    private static function panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, MultiFactorAuthenticationProvider> */
    private static function providers(User $user): array
    {
        return MultiFactorChallenge::make()->getEnabledProviders($user);
    }

    private static function credentials(#[SensitiveParameter] string $login, #[SensitiveParameter] string $password): array
    {
        $login = trim($login);
        if (! str_contains($login, '@')) { // the username, as on the till; one used in two companies needs the e-mail
            $matches = User::query()->whereRaw('lower(username) = ?', [mb_strtolower($login)])->limit(2)->pluck('email');
            $login = $matches->count() === 1 ? $matches->first() : $login;
        }

        return ['email' => mb_strtolower($login), 'password' => $password];
    }

    private static function failed(?User $user, array $credentials): never
    {
        event(new Failed('web', $user, $credentials));

        throw ValidationException::withMessages(['login' => 'These details do not match an account that may sign in here.']);
    }

    /** Where to go after signing in: the page asked for (only one of the new back office), else the person's home. */
    private static function next(Request $request, User $user): string
    {
        $to = (string) $request->session()->pull('url.intended', '');
        $path = parse_url($to, PHP_URL_PATH) ?: '';
        $query = parse_url($to, PHP_URL_QUERY);

        return str_starts_with($path, '/office/') && ! str_starts_with($path, '/office/sign-in') ? $path.($query ? "?{$query}" : '') : UserPrefs::officeHomeUrl($user);
    }

    /** The sign-in page: the company's name and logo (one-company servers), the product's tagline, and a challenge in progress. */
    public function state(Request $request): JsonResponse
    {
        $brand = PanelBrand::current();
        $pending = $this->pending($request);

        return response()->json(['company' => PanelBrand::name(), 'color' => $brand['color'], 'logo' => $brand['has_logo'] ? $brand['logo_url'] : (string) config('brand.logo'),
            'logo_dark' => $brand['has_logo'] ? $brand['logo_dark_url'] : (string) config('brand.logo_dark'), 'tagline' => Branding::poweredBy() ? (string) config('brand.tagline') : null,
            'signed_in' => $request->user() !== null, 'challenge' => $pending ? $this->challenge($pending['user'], $pending['provider']) : null,
            'passkey_message' => $request->session()->get('authenticatePasskey::message')]);
    }

    /** @return array{user: User, provider: string, remember: bool, hash: string}|null */
    private function pending(Request $request): ?array
    {
        $p = $request->session()->get(self::PENDING);
        if (! is_array($p) || ($p['until'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::PENDING);

            return null;
        }
        $user = User::find($p['user']);
        if (! $user) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        return ['user' => $user, 'provider' => (string) $p['provider'], 'remember' => (bool) $p['remember'], 'hash' => (string) $p['hash']];
    }

    private function challenge(User $user, string $provider): array
    {
        self::panel();
        $all = self::providers($user);
        $app = $all['app'] ?? null;

        return ['provider' => $provider, 'providers' => collect($all)->map(fn (MultiFactorAuthenticationProvider $p) => ['id' => $p->getId(), 'label' => $p->getLoginFormLabel()])->values()->all(),
            'recoverable' => $app instanceof AppAuthentication && $app->isRecoverable(), 'name' => $user->name];
    }

    /** Step 1: the e-mail or username and the password. */
    public function signIn(Request $request): JsonResponse
    {
        self::panel();
        $d = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:255'], 'remember' => ['boolean']],
            ['login.*' => 'Enter your e-mail or username.', 'password.*' => 'Enter your password.']);
        $key = 'office-sign-in:'.sha1((string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many tries. Wait '.RateLimiter::availableIn($key).' seconds, then try again.']);
        }
        RateLimiter::hit($key);
        $credentials = self::credentials($d['login'], $d['password']);
        $remember = (bool) ($d['remember'] ?? false);
        $guard = Auth::guard('web');
        $provider = $guard->getProvider();

        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($credentials, $remember, $provider) {
            event(new Attempting('web', $credentials, $remember));
            $user = $provider->retrieveByCredentials($credentials);
            if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials) || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
                self::failed($user instanceof User ? $user : null, $credentials); // inside the Timebox: every failure takes as long
            }
            $timebox->returnEarly();

            return $user;
        }, (int) config('auth.timebox_duration', 200_000));

        $request->session()->forget(self::PENDING);
        $challenge = MultiFactorChallenge::make();
        if ($first = $challenge->getFirstEnabledProvider($user)) {
            $request->session()->put(self::PENDING, ['user' => $user->id, 'provider' => $first->getId(), 'remember' => $remember, 'hash' => sha1((string) $user->getAuthPassword()),
                'until' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp()]);
            $challenge->beforeChallenge($user, $first); // a code by WhatsApp or e-mail is sent now

            return response()->json(['challenge' => $this->challenge($user, $first->getId())]);
        }

        return $this->start($request, $user, $remember);
    }

    /** Step 2: the code from the app, a recovery code, or the code that was sent. */
    public function verify(Request $request): JsonResponse
    {
        self::panel();
        $p = $this->pending($request) ?? throw ValidationException::withMessages(['code' => 'Sign in again: this step has expired.']);
        $user = $p['user'];
        $d = $request->validate(['code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:40']]);
        $challenge = MultiFactorChallenge::make();
        if ($challenge->isRateLimited($user)) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Wait '.$challenge->getRateLimiterAvailableInSeconds($user).' seconds, then try again.']);
        }
        $challenge->hitRateLimiter($user);
        $provider = self::providers($user)[$p['provider']] ?? throw ValidationException::withMessages(['code' => 'Sign in again.']);
        $ok = app(Timebox::class)->call(function (Timebox $timebox) use ($provider, $user, $d) {
            $code = preg_replace('/\s+/', '', (string) ($d['code'] ?? ''));
            $ok = match (true) {
                $provider instanceof AppAuthentication && filled($d['recovery_code'] ?? null) => $provider->isRecoverable() && filled($user->getAppAuthenticationRecoveryCodes()) // all used: simply not right
                    && $provider->verifyRecoveryCode(trim((string) $d['recovery_code']), $user),
                $provider instanceof AppAuthentication => $code !== '' && $provider->verifyCode($code, $provider->getSecret($user), shouldPreventCodeReuse: true),
                $provider instanceof SignInCode => $code !== '' && $provider->verify($user, $code),
                default => false,
            };
            if ($ok) {
                $timebox->returnEarly();
            }

            return $ok;
        }, (int) config('auth.timebox_duration', 200_000));
        if (! $ok) {
            throw ValidationException::withMessages([filled($d['recovery_code'] ?? null) ? 'recovery_code' : 'code' => filled($d['recovery_code'] ?? null)
                ? 'That recovery code is not right, or was already used.' : 'That code is not right. Enter the code shown now, or send a new one.']);
        }
        // Checked again before the session starts: a password changed or an account switched off meanwhile counts.
        $user->refresh();
        if (! $user->canAccessPanel(Filament::getPanel('admin')) || sha1((string) $user->getAuthPassword()) !== $p['hash']) {
            $request->session()->forget(self::PENDING);
            self::failed($user, ['email' => $user->email]);
        }

        return $this->start($request, $user, $p['remember']);
    }

    /** The other way of the challenge (the app, or a code by message), or a new code sent (by e-mail when asked). */
    public function choose(Request $request): JsonResponse
    {
        self::panel();
        $p = $this->pending($request) ?? throw ValidationException::withMessages(['code' => 'Sign in again: this step has expired.']);
        $d = $request->validate(['provider' => ['required', 'string'], 'email' => ['boolean']]);
        $provider = self::providers($p['user'])[$d['provider']] ?? throw ValidationException::withMessages(['provider' => 'Choose one of your ways of signing in.']);
        $request->session()->put(self::PENDING.'.provider', $provider->getId());
        $sent = null;
        if ($provider instanceof SignInCode) {
            $sent = $provider->send($p['user'], (bool) ($d['email'] ?? false));
            if (! $sent) {
                throw ValidationException::withMessages(['code' => 'Too many codes were sent. Wait 10 minutes and try again.']);
            }
        }

        return response()->json(['challenge' => $this->challenge($p['user'], $provider->getId()), 'sent_to' => $sent]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $request->session()->forget(self::PENDING);

        return response()->json(['ok' => true]);
    }

    private function start(Request $request, User $user, bool $remember): JsonResponse
    {
        $request->session()->forget(self::PENDING);
        Auth::guard('web')->login($user, $remember); // the Login event: audit, new computer, support notice
        $request->session()->regenerate();
        RateLimiter::clear('office-sign-in:'.sha1((string) $request->ip()));

        return response()->json(['signed_in' => true, 'open' => self::next($request, $user)]);
    }

    /** Signing out: the session ends everywhere it was this browser's (the Logout event records it). */
    public function signOut(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['open' => '/office/sign-in']);
    }
}
