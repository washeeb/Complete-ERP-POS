<?php

namespace App\Security;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Why someone may not open a page, in plain words (owner request 2026-10-22: "show the reason" instead of a bare 403):
 * the exception's own message when it says something, else the permission the last failed check asked for (recorded by
 * the Authorizer during this request) and where; plus who in the company can give access.
 */
final class AccessReason
{
    /** @var list<array{0: string, 1: ?Model}> permission checks that said no during this request, newest last */
    private static array $denied = [];

    public static function denied(string $permission, ?Model $target): void
    {
        self::$denied[] = [$permission, $target];
        if (count(self::$denied) > 20) {
            array_shift(self::$denied);
        }
    }

    public static function forget(): void
    {
        self::$denied = [];
    }

    /** @return array{reason: string, permission: ?string, admins: ?string} */
    public static function explain(?Throwable $e): array
    {
        $message = trim((string) $e?->getMessage());
        [$permission, $target] = self::$denied ? self::$denied[array_key_last(self::$denied)] : [null, null];
        $label = $permission ? (Permission::whereKey($permission)->value('label') ?? $permission) : null;
        $where = $target && isset($target->name) ? " at {$target->name}" : '';
        $reason = match (true) {
            $message !== '' && ! in_array($message, ['Forbidden', 'This action is unauthorized.', 'Unauthorized.', 'Access denied.'], true) => $message,
            $label !== null => "it needs the permission “{$label}”{$where}, and none of your roles gives it".($where ? ' there' : '').'.',
            default => 'none of your roles includes this page.',
        };

        return ['reason' => ucfirst($reason), 'permission' => $permission, 'admins' => self::admins()];
    }

    /** Up to three people who can give access (roles), by name. */
    public static function admins(): ?string
    {
        $me = auth()->user();
        if (! $me instanceof User) {
            return null;
        }
        $authz = app(Authorizer::class);
        $names = User::where('tenant_id', $me->tenant_id)->where('is_active', true)->where('id', '<>', $me->id)
            ->where('security_level', '<', SecurityLevel::Platform->value)->orderByDesc('security_level')->limit(30)->get()
            ->filter(fn (User $u) => $authz->allowsAnywhere($u, 'admin.roles.manage'))->take(3)->pluck('name');

        return $names->isEmpty() ? null : $names->implode(', ');
    }
}
