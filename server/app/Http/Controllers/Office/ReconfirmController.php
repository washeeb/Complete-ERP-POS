<?php

namespace App\Http\Controllers\Office;

use App\Filament\Pages\ConfirmItsYou;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\Passkeys\AuthenticationOptions;
use App\Security\Reconfirm;
use App\Security\SignInCode;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Support\Config;
use Throwable;

/**
 * "Confirm it's you" in the new back office (part 40), as the current page (App\Filament\Pages\ConfirmItsYou): before
 * money leaves, the person proves it is them with the code from their authenticator app, a passkey, a code sent by
 * WhatsApp or e-mail, or — with none of these — their password. Five tries a minute. Confirming lasts Reconfirm::MINUTES
 * and is audited (ConfirmItsYou::confirmed). The passkey is checked here (user verification required, the passkey must be
 * the person's own) without signing in again.
 */
class ReconfirmController extends Controller
{
    private const SENT = 'reconfirm.code_sent';

    private const PASSKEY_OPTIONS = 'reconfirm.passkey_options';

    private static function hasApp(User $u): bool
    {
        return filled($u->app_authentication_secret);
    }

    private static function hasPasskey(User $u): bool
    {
        return $u->passkeys()->exists();
    }

    public function show(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['confirmed' => Reconfirm::recent(), 'minutes' => Reconfirm::MINUTES, 'app' => self::hasApp($u), 'passkey' => self::hasPasskey($u),
            'password' => ! self::hasApp($u) && ! self::hasPasskey($u), 'sent_to' => $request->session()->get(self::SENT)]);
    }

    /** A code by WhatsApp or e-mail instead (at most three in 10 minutes, as at sign-in). */
    public function send(Request $request, SignInCode $codes): JsonResponse
    {
        $to = $codes->send($request->user());
        if (! $to) {
            throw ValidationException::withMessages(['code' => 'Too many codes were sent. Wait 10 minutes and try again.']);
        }
        $request->session()->put(self::SENT, $to);

        return response()->json(['sent_to' => $to]);
    }

    /** The code (from the app, or the one sent), or the password for someone with neither an app nor a passkey. */
    public function confirm(Request $request, SignInCode $codes): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['code' => ['nullable', 'string', 'max:20'], 'password' => ['nullable', 'string', 'max:255']]);
        $key = "reconfirm:{$u->id}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Wait a minute, then try again.']);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $code = preg_replace('/\s+/', '', (string) ($d['code'] ?? ''));
        $password = (string) ($d['password'] ?? '');
        $sent = $request->session()->has(self::SENT);
        $ok = ($code !== '' && ((self::hasApp($u) && app(AppAuthentication::class)->verifyCode($code, $u->getAppAuthenticationSecret()))
                || ($sent && $codes->verify($u, $code))))
            || (! self::hasApp($u) && ! self::hasPasskey($u) && $password !== '' && Hash::check($password, $u->password));
        if (! $ok) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([$code !== '' ? 'code' : 'password' => 'That did not match. Check the code (or password) and try again.']);
        }
        RateLimiter::clear($key);
        $request->session()->forget(self::SENT);
        ConfirmItsYou::confirmed($u, $code !== '' ? 'code' : 'password');

        return response()->json(['confirmed' => true, 'minutes' => Reconfirm::MINUTES]);
    }

    /** With a passkey, step 1: the challenge (the authenticator must verify the person), kept in this session for step 2. */
    public function passkeyOptions(Request $request): JsonResponse
    {
        abort_unless(self::hasPasskey($request->user()), 422, 'You have no passkey on this account.');
        $options = Config::getAction('generate_passkey_authentication_options', AuthenticationOptions::class)->execute();
        $request->session()->forget('passkey-authentication-options'); // this challenge is for confirming, not for signing in
        $request->session()->put(self::PASSKEY_OPTIONS, $options);

        return response()->json(['options' => json_decode($options)]);
    }

    /** With a passkey, step 2: the browser's answer, checked against the challenge — and the passkey must be the person's own. */
    public function passkey(Request $request): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['answer' => ['required', 'string']]);
        $key = "reconfirm:{$u->id}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['answer' => 'Too many tries. Wait a minute, then try again.']);
        }
        $options = $request->session()->pull(self::PASSKEY_OPTIONS);
        if (! $options) {
            throw ValidationException::withMessages(['answer' => 'Start again: press Confirm with a passkey.']);
        }
        try {
            $passkey = Config::getAction('find_passkey', FindPasskeyToAuthenticateAction::class)->execute($d['answer'], $options);
        } catch (Throwable) {
            $passkey = null;
        }
        if (! $passkey || (string) $passkey->authenticatable_id !== (string) $u->id) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['answer' => 'That passkey could not confirm it is you. Use one of your own passkeys, or a code.']);
        }
        RateLimiter::clear($key);
        ConfirmItsYou::confirmed($u, 'passkey');

        return response()->json(['confirmed' => true, 'minutes' => Reconfirm::MINUTES]);
    }
}
