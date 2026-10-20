<?php

namespace App\Domain\Messaging;

use App\Models\Branch;
use App\Models\User;
use App\Security\Authorizer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** In-app notifications (the bell in the back office) for the people who hold a permission where something happened. */
final class Notifier
{
    public function __construct(private readonly Authorizer $authz) {}

    /** @return int how many people were notified */
    /** @param  string|list<string>  $permission  people holding any of them (each person once) */
    public function toPermission(string $tenantId, string|array $permission, ?string $branchId, string $title, ?string $body = null, ?string $url = null, array $exceptUserIds = []): int
    {
        $branch = $branchId ? Branch::withoutGlobalScopes()->find($branchId) : null;
        $users = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->whereNotIn('id', array_filter($exceptUserIds))->get()
            ->filter(fn (User $u) => collect((array) $permission)->contains(fn ($p) => $branch ? $this->authz->allows($u, $p, $branch) : $this->authz->allowsAnywhere($u, $p)))->values();
        if ($users->isEmpty()) {
            return 0;
        }
        $n = Notification::make()->title($title)->body($body);
        if ($url) {
            $n->actions([Action::make('open')->label('Open')->url($url)->markAsRead()]);
        }
        $n->sendToDatabase($users);

        return $users->count();
    }
}
