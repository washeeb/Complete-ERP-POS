<?php

namespace App\Domain\Access;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Mail\PlainMessage;
use App\Models\AuditEvent;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\ClientInfo;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign-in protection (owner decision 2026-11-13):
 *  - the owner's and IT accounts are told when they sign in from a computer or phone they never used before, by
 *    notification and e-mail, with "This wasn't me": that signs the account out everywhere and, unless unticked, locks
 *    it until SyncLytics support has checked with the person and set a new password;
 *  - IT Admin and SyncLytics support are signed out after 15 minutes without a click or key (SignOutWhenIdle);
 *  - IT Admin and SyncLytics support sign in with the authenticator app or a passkey, never a WhatsApp or e-mail code
 *    alone, and the owner and IT always need two steps whatever the company rule (User::requiresMfa).
 * A computer is the browser's lasting ID (TrackComputer, cookie ses_computer), recorded with every sign-in.
 */
final class SignInGuard
{
    public const IDLE_MINUTES = 15;

    /** Told about a sign-in from a new computer: the owner and IT. */
    public static function isWatched(User $u): bool
    {
        return ! $u->isSupport() && (OwnerSeparation::isOwnerAccount($u) || self::isTechnical($u));
    }

    /** Signed out after 15 idle minutes, and only with the authenticator app or a passkey: IT and SyncLytics support. */
    public static function isStrict(User $u): bool
    {
        return $u->isSupport() || self::isTechnical($u);
    }

    /** Holds a technical role (IT Admin) now. */
    public static function isTechnical(User $u): bool
    {
        return RoleAssignment::withoutGlobalScopes()->where('user_id', $u->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('role', fn ($q) => $q->withoutGlobalScopes()->where('is_technical', true))->exists();
    }

    /** Called on each back-office sign-in, before it is recorded. */
    public function signedIn(User $user, ?Request $request): void
    {
        if (! $user->tenant_id || ! self::isWatched($user)) {
            return;
        }
        $client = ClientInfo::from($request) ?? [];
        $key = $client['computer_key'] ?? null;
        if (! $key || ! Str::isUuid($key)) {
            return;
        }
        $before = AuditEvent::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->where('actor_id', $user->id)->where('action', 'auth.signed_in');
        if (! (clone $before)->exists() || (clone $before)->where('client->computer_key', $key)->exists()) {
            return; // the first sign-in ever has nothing to compare with; a known computer is fine
        }
        $where = ClientInfo::summary($client, $request?->ip()) ?? 'an unknown computer';
        $at = now()->timezone($user->tenant?->timezone ?: 'UTC')->format('j M Y H:i');
        $url = URL::temporarySignedRoute('account.not-me', now()->addDays(7), ['user' => $user->id, 'computer' => $key]);
        DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($user, 'web'), 'auth.new_computer', $user, [], $where));

        Notification::make()->warning()->title('Your account signed in on a new computer')
            ->body("{$at}: {$where}. If this was you, there is nothing to do. If it was not, press This wasn't me at once.")
            ->actions([Action::make('notMe')->label("This wasn't me")->url($url)->color('danger')])->sendToDatabase($user);
        if ($user->email) {
            try {
                Mail::to($user->email)->send(new PlainMessage('New sign-in to your ZionEX account',
                    "Your account ({$user->username}) signed in on a computer or phone it never used before:\n\n{$at}\n{$where}\n\n"
                    ."If this was you, there is nothing to do.\n\nIf it was NOT you, open this link at once: it signs your account out everywhere and locks it until SyncLytics support calls you:\n{$url}\n\n"
                    .'The link works for 7 days.'));
            } catch (Throwable) {
                // the notification in the back office still stands
            }
        }
    }

    /**
     * "This wasn't me": signed out everywhere (sessions, remembered sign-ins, till and API tokens); that computer is no
     * longer trusted; with $lock the account stays off until SyncLytics support switches it on with a new password.
     */
    public function notMe(User $user, string $computerKey, bool $lock): void
    {
        $actor = Actor::system($user->tenant_id, "{$user->name} (This wasn't me)", 'web');
        DB::transaction(function () use ($user, $computerKey, $lock, $actor) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => Str::random(60)] + ($lock ? ['is_active' => false] : []))->saveQuietly();
            $user->tokens()->delete();
            app(AuditLog::class)->record($actor, 'auth.not_me', $user, $lock ? ['is_active' => [true, false]] : [],
                'Said a sign-in was not them (computer '.Str::limit($computerKey, 13, '').'): signed out everywhere'.($lock ? ', account locked' : ''));
        });

        $what = "{$user->name} ({$user->username}) says a sign-in to their account was not them. The account was signed out everywhere"
            .($lock ? ' and locked: call them, check it is really them, then switch it on again with a new password and a new authenticator.' : '.');
        app(Notifier::class)->toPermission($user->tenant_id, ['settings.business', 'admin.users.manage'], null, 'A sign-in was reported as not genuine', $what, null, [$user->id], topic: 'security');
        if ($to = config('erp.support_email')) {
            try {
                Mail::to($to)->send(new PlainMessage("[{$user->tenant?->name}] Sign-in reported as not genuine", $what));
            } catch (Throwable) {
                // the notification in the back office still stands
            }
        }
    }
}
