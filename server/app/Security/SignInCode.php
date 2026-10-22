<?php

namespace App\Security;

use App\Domain\Messaging\Gateways;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Phone;
use App\Domain\Messaging\Templates;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Mail\PlainMessage;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;
use Throwable;

/**
 * Second step by a code (owner request 2026-10-22): for people whose administrator chose "Password and a code", a
 * six-digit code goes to their WhatsApp (when the business has a WhatsApp provider and the person a phone number) or
 * else their e-mail, and works once for 10 minutes. The code itself is never stored or logged — only its hash, in the
 * person's session — and at most three are sent in 10 minutes.
 */
final class SignInCode implements HasBeforeChallengeHook, MultiFactorAuthenticationProvider
{
    public const MINUTES = 10;

    public static function make(): static
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return 'signin_code';
    }

    public function getLoginFormLabel(): string
    {
        return 'Code by WhatsApp or e-mail';
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return $user instanceof User && $user->signin_method === 'code';
    }

    public function getManagementSchemaComponents(): array
    {
        return []; // set by the administrator on the person's account, not by the person
    }

    public function beforeChallenge(Authenticatable $user): void
    {
        if ($user instanceof User) {
            $this->send($user);
        }
    }

    /** Send a new code. Returns where it went ("WhatsApp …2222" / "e-mail a…@x.com"), or null when too many were sent. */
    public function send(User $user, bool $email = false): ?string
    {
        $key = "signin-code:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return null;
        }
        RateLimiter::hit($key, self::MINUTES * 60);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        session()->put($this->sessionKey($user), [Hash::make($code), now()->addMinutes(self::MINUTES)->getTimestamp()]);
        $company = Tenant::find($user->tenant_id)?->name ?? config('app.name');
        $vars = ['code' => $code, 'company' => $company, 'minutes' => self::MINUTES];
        $to = null;
        if (! $email && $user->phone && Phone::looksValid($user->phone) && ($provider = app(Messenger::class)->provider($user->tenant_id, 'whatsapp'))
            && ($t = Templates::find($user->tenant_id, 'signin_code', 'whatsapp'))?->is_active) {
            try {
                Gateways::whatsapp($provider->credentials ?? [], $user->phone, (string) ($t->whatsapp_template ?: 'signin_code'), (string) ($t->whatsapp_language ?: 'en'),
                    Templates::render($t, $vars)[2]);
                $to = 'WhatsApp …'.substr(preg_replace('/\D/', '', $user->phone), -4);
            } catch (Throwable) {
                $to = null; // WhatsApp is down: e-mail instead
            }
        }
        if ($to === null) {
            $t = Templates::find($user->tenant_id, 'signin_code', 'email');
            [$subject, $body] = $t ? Templates::render($t, $vars) : ['Your sign-in code', "Your sign-in code for {$company} is {$code}. It works for ".self::MINUTES.' minutes.'];
            Mail::to($user->email)->send(new PlainMessage($subject ?? 'Your sign-in code', $body));
            $to = 'e-mail '.self::maskEmail($user->email);
        }
        DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($user, 'web'), 'auth.code_sent', $user, ['to' => [null, $to]]));

        return $to;
    }

    public function verify(User $user, #[SensitiveParameter] string $code): bool
    {
        [$hash, $until] = session($this->sessionKey($user)) ?? [null, 0];
        if (! $hash || now()->getTimestamp() > $until || ! Hash::check(trim($code), $hash)) {
            return false;
        }
        session()->forget($this->sessionKey($user));

        return true;
    }

    public function getChallengeFormComponents(Authenticatable $user): array
    {
        /** @var User $user */
        $resend = fn (bool $email) => function () use ($user, $email) {
            $to = $this->send($user, $email);
            $to ? Notification::make()->success()->title("A new code was sent to your {$to}")->send()
                : Notification::make()->danger()->title('Too many codes were sent. Wait 10 minutes and try again.')->send();
        };

        return [
            OneTimeCodeInput::make('code')->label('The code we sent you')->validationAttribute('code')->required()
                ->belowContent([
                    Action::make('resend')->label('Send a new code')->link()->action($resend(false)),
                    Action::make('byEmail')->label('Send it by e-mail instead')->link()->action($resend(true)),
                ])
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] $value, Closure $fail) use ($user): void {
                    if (! is_string($value) || ! $this->verify($user, $value)) {
                        $fail('That code is wrong or has expired. Send a new one.');
                    }
                }),
        ];
    }

    private function sessionKey(User $user): string
    {
        return "signin_code:{$user->id}";
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'…@'.$domain;
    }
}
