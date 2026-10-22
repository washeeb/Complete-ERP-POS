<?php

namespace App\Http\Controllers;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Filament\Resources\Users\UserResource;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Ask for access" on the no-access page (owner request 2026-10-22): everyone who may give roles is told, on the bell,
 * who asked, for which page, which permission it needs and the person's message; the request is in the audit log.
 * Nothing is granted by itself — an administrator decides.
 */
class AccessRequestController extends Controller
{
    public function __invoke(Request $request, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:500'],
            'permission' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $path = str_starts_with($data['path'], '/') ? $data['path'] : '/'.$data['path']; // a page on this server only
        $label = filled($data['permission'] ?? null) ? (Permission::whereKey($data['permission'])->value('label') ?? null) : null;
        $body = "Page: {$path}".($label ? "\nNeeds: {$label}" : '').(filled($data['note'] ?? null) ? "\nMessage: ".trim($data['note']) : '');
        $told = $notifier->toPermission($user->tenant_id, 'admin.roles.manage', null, "{$user->name} asks for access", $body,
            UserResource::getUrl('edit', ['record' => $user], panel: 'admin'), [$user->id]);
        DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($user, 'web'), 'access.requested', $user,
            ['page' => [null, mb_substr($path, 0, 200)], 'permission' => [null, $data['permission'] ?? null]]));

        return back()->with('access_requested', $told);
    }
}
