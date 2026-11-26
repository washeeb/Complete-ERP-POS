<?php

namespace App\Http\Controllers\Office;

use App\Domain\Access\AccessService;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\User;
use App\Support\Look;
use App\Support\NotifyPrefs;
use App\Support\Settings;
use App\Support\UserPrefs;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Support\Config;
use Throwable;

/**
 * My profile in the new back office (part 39), as the current page: details and photo, the look, preferences,
 * notifications and password (AccessService::updateOwnProfile, audited), two-factor sign-in with the authenticator app
 * (set up, new recovery codes, turn off — Filament's own AppAuthentication provider, with its password and code checks and
 * rate limits) and passkeys (spatie/laravel-passkeys: register, remove; audited by the Passkey model).
 */
class ProfileController extends Controller
{
    use ActsOnAccess;

    private const TOKEN_MINUTES = 15;

    /** The panel's own authenticator-app provider (recovery codes, code window, brand name as configured). */
    private function app(): AppAuthentication
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $p = Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app'] ?? null;
        abort_unless($p instanceof AppAuthentication, 404);

        return $p;
    }

    private static function pairs(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    /** Five tries a minute per person and step, as the current page's actions (then a wait). */
    private static function limit(string $key, User $user, string $field): void
    {
        $k = "{$key}:{$user->id}";
        if (RateLimiter::tooManyAttempts($k, 5)) {
            throw ValidationException::withMessages([$field => 'Too many tries. Wait a minute, then try again.']);
        }
        RateLimiter::hit($k);
    }

    private static function checkPassword(User $user, #[SensitiveParameter] ?string $password): void
    {
        if (! is_string($password) || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your current password.']);
        }
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $app = $this->app();
        $company = (string) app(Settings::class)->get('ui.look', $user->tenant_id);
        $company = isset(Look::LOOKS[$company]) ? $company : Look::DEFAULT;
        $prefs = UserPrefs::for($user);

        return response()->json([
            'name' => $user->name, 'email' => $user->email, 'photo' => $user->getFilamentAvatarUrl(), 'has_photo' => (bool) $user->avatar_path,
            'look' => (string) ($user->ui_look ?? ''), 'looks' => [['id' => '', 'label' => 'As the company chose', 'detail' => (string) (Look::LOOKS[$company] ?? '')],
                ['id' => 'top', 'label' => 'Tabs across the top', 'detail' => 'Like a desktop program'], ['id' => 'side', 'label' => 'Side menu', 'detail' => 'A dark menu that folds to icons'],
                ['id' => 'classic', 'label' => 'Classic', 'detail' => 'The original layout']],
            'prefs' => $prefs, 'homes' => self::pairs(UserPrefs::homes($user)), 'rows' => array_map(fn ($r) => ['id' => (string) $r, 'label' => (string) $r], UserPrefs::ROWS),
            'dates' => self::pairs(array_map(fn ($d) => $d[0], UserPrefs::DATES)), 'themes' => self::pairs(UserPrefs::THEMES), 'densities' => self::pairs(UserPrefs::DENSITIES),
            'office_looks' => self::pairs(UserPrefs::OFFICE_LOOKS),
            'channels' => self::pairs(NotifyPrefs::CHANNELS),
            'topics' => collect(NotifyPrefs::TOPICS)->map(fn ($t, $topic) => ['id' => $topic, 'label' => $t[0], 'detail' => $t[1], 'channels' => $t[2],
                'on' => array_values(array_filter($t[2], fn ($c) => NotifyPrefs::wants($user, $topic, $c)))])->values()->all(),
            'two_factor' => ['enabled' => $app->isEnabled($user), 'recoverable' => $app->isRecoverable(), 'can_regenerate' => $app->isRecoverable() && $app->canRegenerateRecoveryCodes(),
                'codes_left' => $app->isEnabled($user) ? count($user->getAppAuthenticationRecoveryCodes() ?? []) : 0, 'required' => $user->requiresMfa(),
                'by_code' => $user->signin_method === 'code'],
            'passkeys' => $user->passkeys()->orderBy('created_at')->get()->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name,
                'last_used' => $p->last_used_at?->diffForHumans() ?? 'not yet'])->all(),
            'help' => ['vendor' => config('brand.vendor'), 'phone' => config('brand.support_phone'), 'email' => config('brand.support_email')],
        ]);
    }

    /**
     * Details, look, preferences, notifications and the password, as the current page saves them. The current password is
     * asked when the password or the e-mail address changes; the session keeps working with a new password.
     */
    public function save(Request $request, AccessService $access): JsonResponse
    {
        $user = $request->user();
        self::limit('office-profile', $user, 'name');
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id.',id'],
            'look' => ['nullable', 'string'], 'prefs' => ['array'], 'notify' => ['array'], 'notify.*' => ['array'], 'notify.*.*' => ['string'],
            'password' => ['nullable', 'string', Password::default(), 'confirmed'], 'current_password' => ['nullable', 'string']],
            ['name.*' => 'Enter your name.', 'email.unique' => 'Someone else uses that e-mail address.', 'email.*' => 'Enter your e-mail address.',
                'password.confirmed' => 'The two new passwords are not the same.']);
        $changing = filled($d['password'] ?? null) || mb_strtolower($d['email']) !== mb_strtolower((string) $user->email);
        if ($changing && (! filled($d['current_password'] ?? null) || ! Hash::check((string) $d['current_password'], $user->password))) {
            throw ValidationException::withMessages(['current_password' => 'Enter your current password to change your password or e-mail address.']);
        }
        $data = ['name' => $d['name'], 'email' => $d['email'], 'ui_look' => (string) ($d['look'] ?? ''),
            // The whole set of preferences, so the ones this page does not show (the new look's menu, Home) are kept.
            'ui_prefs' => array_replace((array) ($user->ui_prefs ?? []), UserPrefs::for($user), array_intersect_key((array) ($d['prefs'] ?? []), array_flip(['home', 'rows', 'date', 'theme', 'density', 'office_look']))),
            'notify_prefs' => collect(NotifyPrefs::TOPICS)->map(fn ($t, $topic) => collect($t[2])->mapWithKeys(fn ($c) => [$c => in_array($c, (array) ($d['notify'][$topic] ?? []), true)])->all())->all()];
        if (filled($d['password'] ?? null)) {
            $data['password'] = $d['password'];
        }
        $out = $this->act(fn (Actor $actor) => ['message' => $access->updateOwnProfile($actor, $user, $data) ? 'Saved.' : '']);
        if ($out->getStatusCode() === 200 && isset($data['password']) && $request->hasSession()) {
            $request->session()->put(['password_hash_web' => $user->fresh()->getAuthPassword()]); // stay signed in with the new password
        }

        return $out;
    }

    /** A new photo (cut to a square and kept on the company's own server), or none. */
    public function photo(Request $request, AccessService $access): JsonResponse
    {
        $user = $request->user();
        if ($request->boolean('remove')) {
            return $this->act(fn (Actor $actor) => ['message' => $access->updateOwnProfile($actor, $user, ['photo' => null]) ? 'Photo removed.' : '', 'photo' => null]);
        }
        $file = $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:10240']],
            ['photo.*' => 'Choose a JPEG, PNG or WebP photo up to 10 MB.'])['photo'];
        $path = $file->storeAs(AccessService::PHOTO_UPLOADS, Str::uuid7().'.'.$file->extension(), 'local');

        $out = $this->act(fn (Actor $actor) => ['message' => $access->updateOwnProfile($actor, $user, ['photo' => $path]) ? 'Photo saved.' : '',
            'photo' => $user->fresh()->getFilamentAvatarUrl()]);
        Storage::disk('local')->delete($path); // kept only if it failed before being stored (updateOwnProfile deletes it when used)

        return $out;
    }

    // -------------------------------------------------------------------------------------------- Two-factor sign-in

    /** "Set up two-factor sign-in first" (part 41): who is asked, and whether it is done (open while the rule asks for it). */
    public function setupState(Request $request): JsonResponse
    {
        $user = $request->user();
        $app = $this->app();

        return response()->json(['name' => $user->name, 'enabled' => $app->isEnabled($user), 'required' => $user->requiresMfa(), 'home' => UserPrefs::officeHomeUrl($user)]);
    }

    /** Set up, step 1: a new secret (and recovery codes), sealed for this person for 15 minutes; the QR code to scan. */
    public function twoFactorStart(Request $request): JsonResponse
    {
        $user = $request->user();
        $app = $this->app();
        abort_if($app->isEnabled($user), 422, 'Two-factor sign-in is already on.');
        $secret = $app->generateSecret();
        $token = encrypt(['secret' => $secret, 'codes' => $app->isRecoverable() ? $app->generateRecoveryCodes() : null, 'user' => $user->id,
            'until' => now()->addMinutes(self::TOKEN_MINUTES)->getTimestamp()]);

        return response()->json(['token' => $token, 'qr' => $app->generateQrCodeDataUri($secret), 'secret' => $secret]);
    }

    /** Set up, step 2: the code from the app and the password; then it is on, and the recovery codes are shown once. */
    public function twoFactorConfirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $app = $this->app();
        $d = $request->validate(['token' => ['required', 'string'], 'code' => ['required', 'string', 'max:12'], 'password' => ['required', 'string']],
            ['code.*' => 'Enter the code from the app.', 'password.*' => 'Enter your password.']);
        self::limit('filament-set-up-app-authentication', $user, 'code');
        try {
            $t = decrypt($d['token']);
        } catch (Throwable) {
            $t = null;
        }
        if (! is_array($t) || ($t['user'] ?? null) !== $user->id || ($t['until'] ?? 0) < now()->getTimestamp()) {
            throw ValidationException::withMessages(['code' => 'This set-up has expired. Start again.']);
        }
        abort_if($app->isEnabled($user), 422, 'Two-factor sign-in is already on.');
        self::checkPassword($user, $d['password']);
        if (! $app->verifyCode(trim($d['code']), $t['secret'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Enter the code the app shows now.']);
        }
        DB::transaction(function () use ($app, $user, $t) {
            $app->saveSecret($user, $t['secret']);
            if ($app->isRecoverable() && is_array($t['codes'])) {
                $app->saveRecoveryCodes($user, $t['codes']);
            }
        });

        return response()->json(['message' => 'Two-factor sign-in is on.', 'recovery_codes' => $app->isRecoverable() ? $t['codes'] : []]);
    }

    /** New recovery codes (the old ones stop working): the password and a code from the app. Shown once. */
    public function twoFactorRegenerate(Request $request): JsonResponse
    {
        $user = $request->user();
        $app = $this->app();
        abort_unless($app->isEnabled($user) && $app->isRecoverable() && $app->canRegenerateRecoveryCodes(), 422, 'Two-factor sign-in is not on.');
        $d = $request->validate(['code' => ['required', 'string', 'max:12'], 'password' => ['required', 'string']],
            ['code.*' => 'Enter the code from the app.', 'password.*' => 'Enter your password.']);
        self::limit('filament-regenerate-app-authentication-recovery-codes', $user, 'code');
        self::checkPassword($user, $d['password']);
        if (! $app->verifyCode(trim($d['code']), shouldPreventCodeReuse: true)) {
            throw ValidationException::withMessages(['code' => 'That code is not right, or was already used. Wait for the next one.']);
        }
        $codes = $app->generateRecoveryCodes();
        $app->saveRecoveryCodes($user, $codes);

        return response()->json(['message' => 'New recovery codes made. The old ones no longer work.', 'recovery_codes' => $codes]);
    }

    /** Turn it off: the password and a code from the app, or one of the recovery codes. */
    public function twoFactorDisable(Request $request): JsonResponse
    {
        $user = $request->user();
        $app = $this->app();
        abort_unless($app->isEnabled($user), 422, 'Two-factor sign-in is not on.');
        $d = $request->validate(['code' => ['nullable', 'string', 'max:12'], 'recovery_code' => ['nullable', 'string', 'max:40'], 'password' => ['required', 'string']],
            ['password.*' => 'Enter your password.']);
        self::limit('filament-disable-app-authentication', $user, filled($d['recovery_code'] ?? null) ? 'recovery_code' : 'code');
        self::checkPassword($user, $d['password']);
        if (filled($d['recovery_code'] ?? null) && $app->isRecoverable()) {
            if (blank($user->getAppAuthenticationRecoveryCodes()) || ! $app->verifyRecoveryCode(trim($d['recovery_code']), $user)) { // all used: simply not right
                throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not right, or was already used.']);
            }
        } elseif (! filled($d['code'] ?? null) || ! $app->verifyCode(trim($d['code']), shouldPreventCodeReuse: true)) {
            throw ValidationException::withMessages(['code' => 'That code is not right, or was already used. Wait for the next one.']);
        }
        DB::transaction(function () use ($app, $user) {
            $app->saveSecret($user, null);
            if ($app->isRecoverable()) {
                $app->saveRecoveryCodes($user, null);
            }
        });

        return response()->json(['message' => $user->requiresMfa()
            ? 'Two-factor sign-in is off. Your account needs it: you will be asked to set it up again.' : 'Two-factor sign-in is off.']);
    }

    // -------------------------------------------------------------------------------------------- Passkeys

    /** Add a passkey, step 1: the options the browser needs, kept in this session for step 2. */
    public function passkeyOptions(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:255']], ['name.*' => 'Name this passkey, e.g. Work laptop.']);
        $options = Config::getAction('generate_passkey_register_options', GeneratePasskeyRegisterOptionsAction::class)->execute($request->user());
        $request->session()->put('passkey-registration-options', $options);
        $request->session()->put('passkey-registration-name', $d['name']);

        return response()->json(['options' => json_decode($options)]);
    }

    /** Add a passkey, step 2: what the browser made, checked against the options of step 1. */
    public function passkeyStore(Request $request): JsonResponse
    {
        $d = $request->validate(['passkey' => ['required', 'string']]);
        $options = $request->session()->pull('passkey-registration-options');
        $name = (string) $request->session()->pull('passkey-registration-name', 'Passkey');
        if (! $options) {
            throw ValidationException::withMessages(['name' => 'Start again: press Add passkey.']);
        }
        try {
            Config::getAction('store_passkey', StorePasskeyAction::class)->execute($request->user(), $d['passkey'], $options, $request->getHost(), ['name' => $name]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['name' => 'The passkey could not be added. Try again.']);
        }

        return response()->json(['message' => "Passkey “{$name}” added."]);
    }

    public function passkeyRemove(Request $request, string $id): JsonResponse
    {
        $p = $request->user()->passkeys()->whereKey($id)->firstOrFail();
        $p->delete(); // audited by the Passkey model

        return response()->json(['message' => "Passkey “{$p->name}” removed."]);
    }
}
