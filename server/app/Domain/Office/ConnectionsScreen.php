<?php

namespace App\Domain\Office;

use App\Domain\Api\ApiClients;
use App\Domain\Sync\Replication;
use App\Filament\Resources\ApiClients\ApiClientResource;
use App\Filament\Resources\MomoCharges\MomoChargeResource;
use App\Filament\Resources\SupportAccessGrants\SupportAccessGrantResource;
use App\Filament\Resources\SyncPeers\SyncPeerResource;
use App\Filament\Support\Erp;
use App\Models\ApiClient;
use App\Models\Branch;
use App\Models\MomoCharge;
use App\Models\MomoGateway;
use App\Models\SalePayment;
use App\Models\SupportAccessGrant;
use App\Models\SupportReport;
use App\Models\SyncPeer;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * System in the new back office, second half (part 23 of the rewrite), as the current pages: API clients, Mobile money,
 * Support access and Sync Center. Same queries and rules: ApiClients, MomoCollections, RemoteAccess, SyncEngine,
 * SyncFile, NodeSetup and TillFileImport (ConnectionsController). Keys and secrets are shown once, when made.
 */
final class ConnectionsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    // -------------------------------------------------------------------------------------------- API clients

    public static function canApi(): bool
    {
        return ApiClientResource::canViewAny();
    }

    public function apiClients(): array
    {
        $users = User::whereIn('id', ApiClient::pluck('user_id'))->pluck('name', 'id');
        $branches = Branch::pluck('code', 'id');

        return ['openapi' => url('/api/public/v1/openapi.json'), 'scopes' => self::choices(array_map(fn ($s) => $s[0], ApiClients::SCOPES)), 'events' => self::choices(ApiClients::EVENTS),
            'users' => self::choices(User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()), 'branches' => self::choices(Branch::choices(null)),
            'rows' => ApiClient::orderBy('name')->get()->map(fn (ApiClient $c) => ['id' => $c->id, 'name' => $c->name, 'scopes' => array_values((array) $c->scopes),
                'acts_as' => $users[$c->user_id] ?? null, 'branch' => $c->branch_id ? ($branches[$c->branch_id] ?? null) : null, 'last_used' => $c->last_used_at?->diffForHumans(),
                'webhooks' => WebhookEndpoint::where('api_client_id', $c->id)->where('is_active', true)->count(), 'active' => (bool) $c->is_active])->all()];
    }

    // -------------------------------------------------------------------------------------------- Mobile money

    public static function canMomo(): bool
    {
        return MomoChargeResource::canViewAny();
    }

    public const TONE = ['success' => 'success', 'failed' => 'danger', 'expired' => 'danger'];

    /** @param  array<string, mixed>  $p  q (cashier, number, transaction), status, unused, from, to, page, per */
    public function momo(array $p): array
    {
        $q = MomoChargeResource::getEloquentQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('requested_by_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")->orWhere('provider_ref', 'ilike', "%{$s}%"));
        }
        if (array_key_exists($p['status'] ?? '', MomoCharge::STATUSES)) {
            $q->where('status', $p['status']);
        }
        if (! empty($p['unused'])) { // money received with no sale: give it back or ring up the sale
            $q->where('status', 'success')->whereNull('sale_id');
        }
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : null;
        if ($from = $date($p['from'] ?? '')) {
            $q->where('created_at', '>=', $from.' 00:00:00');
        }
        if ($to = $date($p['to'] ?? '')) {
            $q->where('created_at', '<=', $to.' 23:59:59');
        }
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $total = (clone $q)->count();
        $g = MomoGateway::first();
        $wanted = (string) app(Settings::class)->get('momo.confirm', $this->user->tenant_id) !== 'manual';
        $manage = Erp::can('payments.momo.manage');

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(MomoCharge::STATUSES),
            'about' => match (true) {
                $g?->is_active && $wanted => 'Payment requests go through '.MomoGateway::PROVIDERS[$g->provider].'. How the till takes mobile money: System → Settings → Mobile money.',
                (bool) $g?->is_active => MomoGateway::PROVIDERS[$g->provider].' is set up, but System → Settings → Mobile money still says the cashier types the transaction ID. Change that setting to start payment requests.',
                $wanted => 'Settings ask for payment requests, but no payment provider is set up yet — until one is, tills keep taking typed transaction IDs. Press “Payment provider” and enter your Paystack, Hubtel or other gateway account.',
                default => 'Payment requests are off: the cashier types the transaction ID. To start them, press “Payment provider”, then choose it under System → Settings → Mobile money.',
            },
            'gateway' => $manage ? ['provider' => $g?->provider ?? 'paystack', 'active' => (bool) $g?->is_active, 'providers' => self::choices(MomoGateway::PROVIDERS),
                'fields' => collect(MomoGateway::FIELDS)->map(fn ($list, $provider) => collect($list)->map(fn ($label, $key) => ['key' => $key, 'label' => $label,
                    'secret' => ! in_array($key, ['pos_sales_id', 'client_id', 'url'], true),
                    'saved' => $g?->provider === $provider && ! empty(($g->credentials ?? [])[$key]),
                    'value' => $g?->provider === $provider && in_array($key, ['pos_sales_id', 'client_id', 'url'], true) ? (string) (($g->credentials ?? [])[$key] ?? '') : ''])->values()->all())->all(),
                'callback' => $g ? route('momo.webhook', ['token' => $g->webhook_token]) : null] : null,
            'rows' => $q->orderByDesc('created_at')->forPage($page, $per)->get()->map(fn (MomoCharge $c) => ['id' => $c->id, 'when' => $c->created_at?->toIso8601String(),
                'status' => $c->status, 'status_label' => MomoCharge::STATUSES[$c->status] ?? $c->status, 'tone' => self::TONE[$c->status] ?? 'warning',
                'message' => $c->status !== 'success' ? $c->message : null, 'amount' => Money::display((int) $c->amount_minor, $c->currency_code),
                'network' => SalePayment::MOMO_PROVIDERS[$c->network] ?? $c->network, 'phone' => $c->phone,
                'sale' => $c->sale?->receipt_no ?? ($c->status === 'success' ? 'Paid, not on a sale' : null), 'transaction' => $c->provider_ref,
                'branch' => $c->branch?->name, 'till' => $c->device?->name, 'cashier' => $c->requested_by_name])->all()];
    }

    // -------------------------------------------------------------------------------------------- Support access

    public static function canSupport(): bool
    {
        return SupportAccessGrantResource::canViewAny();
    }

    public function supportAccess(): array
    {
        $grant = Erp::can('support.access.grant');

        return ['can_grant' => $grant, 'levels' => self::choices(SupportAccessGrant::LEVELS), 'branches' => self::choices(Branch::choices(null)),
            'durations' => self::choices(['1' => '1 hour', '2' => '2 hours', '4' => '4 hours', '8' => 'The working day (8 hours)', '24' => '24 hours', 'report' => 'Until a problem report is closed']),
            'reports' => $grant ? self::choices(SupportReport::where('status', 'open')->latest()->limit(50)->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->number} · ".mb_strimwidth($r->message, 0, 60, '…')])->all()) : [],
            'rows' => SupportAccessGrant::with('branch', 'report')->orderByDesc('starts_at')->limit(100)->get()->map(function (SupportAccessGrant $g) use ($grant) {
                $state = match (true) {
                    $g->revoked_at !== null => 'Withdrawn', $g->isActive() => 'Open', default => 'Ended'
                };

                return ['id' => $g->id, 'state' => $state, 'tone' => ['Open' => 'warning', 'Withdrawn' => 'subtle'][$state] ?? 'success',
                    'level' => $g->level === 'control' ? 'See and control' : 'See only', 'branch' => $g->branch?->name, 'reason' => $g->reason, 'from' => $g->starts_at?->toIso8601String(),
                    'until' => $g->support_report_id ? 'Problem '.$g->report?->number.' closed' : $g->ends_at?->format('j M Y H:i'), 'by' => $g->granted_by_name,
                    'withdrawn_by' => $g->revoked_by_name, 'can_revoke' => $grant && $g->isActive()];
            })->all()];
    }

    // -------------------------------------------------------------------------------------------- Sync Center

    public static function canSync(): bool
    {
        return SyncPeerResource::canViewAny();
    }

    public function sync(): array
    {
        $branch = config('erp.node.role') === 'branch';

        return ['node' => (string) config('erp.node.id'), 'is_branch' => $branch, 'modes' => self::choices(SyncPeer::MODES),
            'empty' => $branch ? 'Join head office with: php artisan erp:node:join <url> <hq-node-id> <company-id>' : 'Use “Add branch server” to let a branch server sync with this one.',
            'rows' => SyncPeer::orderBy('name')->get()->map(function (SyncPeer $p) {
                $n = fn (string $s) => $p->inbox()->where('status', $s)->count();
                $unsent = SyncPeerResource::unsent($p);
                $waiting = array_filter([$unsent ? "{$unsent} to send" : null, ($x = $n('staged')) ? "{$x} to review" : null, ($x = $n('skipped')) ? "{$x} held (group off)" : null,
                    ($x = $n('failed')) ? "{$x} failed" : null]);
                $review = $p->inbox()->whereIn('status', ['staged', 'skipped'])->selectRaw('sync_group, event_type, count(*) as n')->groupBy('sync_group', 'event_type')->get();
                $status = ! $p->is_active ? 'Disabled' : ($p->last_error ? 'Error' : ($p->last_success_at ? 'OK' : 'Waiting'));

                return ['id' => $p->id, 'name' => $p->name, 'about' => "Node {$p->node_id} · ".($p->role === 'hq' ? 'head office' : 'branch server'),
                    'last_sync' => $p->last_success_at?->diffForHumans(), 'status' => $status, 'tone' => ['OK' => 'success', 'Error' => 'danger'][$status] ?? 'subtle',
                    'waiting' => $waiting ? implode(' · ', $waiting) : 'nothing', 'error' => $p->last_error, 'active' => (bool) $p->is_active, 'can_sync' => $p->base_url && $p->is_active,
                    'can_resync' => (bool) $p->base_url,
                    'review' => $review->isEmpty() ? null : ['items' => $review->map(fn ($r) => ['group' => Replication::GROUPS[$r->sync_group]['label'] ?? $r->sync_group,
                        'event' => $r->event_type, 'n' => (int) $r->n])->all(),
                        'groups' => self::choices($review->pluck('sync_group')->unique()->mapWithKeys(fn ($g) => [$g => Replication::GROUPS[$g]['label'] ?? $g])->all())],
                    'groups' => collect($p->groupsWeReceive())->map(fn ($g) => ['key' => $g, 'label' => Replication::GROUPS[$g]['label'] ?? $g, 'mode' => ($p->groups ?? [])[$g] ?? 'auto'])->values()->all()];
            })->all()];
    }

    public function peersQuery(): Builder
    {
        return SyncPeer::query();
    }
}
