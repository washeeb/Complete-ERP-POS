<?php

namespace App\Domain\Office;

use App\Domain\Pos\TillApprovals;
use App\Filament\Pages\TillRequests;
use App\Filament\Pages\TimeApprovals;
use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Filament\Resources\ReviewItems\ReviewItemResource;
use App\Filament\Support\Erp;
use App\Filament\Widgets\Panels\MyApprovals;
use App\Models\AccessRequest;
use App\Models\ReviewItem;
use App\Models\Tenant;
use App\Models\TillApproval;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * To-do in the new back office: everything waiting for this person's decision, item by item, in one place (owner plan
 * 2026-11-16, release 1). Till requests first (a cashier is waiting at the till), then the approvals of the dashboard
 * (orders, adjustments, returns, claims…, the same rules: only kinds they may approve, where they may, never their
 * own), access requests (owner and SyncLytics support), the review queue and timesheets.
 */
final class TodoData
{
    /** Items shown per kind; the page behind it has the rest. */
    private const PER_KIND = 25;

    public function __construct(private readonly User $user) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $tenant = Tenant::find($this->user->tenant_id);
        $currency = $tenant?->currency_code ?? 'GHS';
        $groups = array_values(array_filter([
            $this->tillRequests(),
            ...$this->approvals(),
            $this->accessRequests(),
            $this->reviewQueue(),
            $this->timesheets(),
        ]));

        return ['currency' => $currency, 'exponent' => Money::exponent($currency), 'groups' => $groups,
            'total' => array_sum(array_map(fn ($g) => $g['count'], $groups))];
    }

    /** Only the number, for the badge on the menu (cheap enough to ask every half minute). */
    public function count(): int
    {
        return (int) $this->build()['total'];
    }

    private function tillRequests(): ?array
    {
        if (! TillRequests::canAccess()) {
            return null;
        }
        $svc = app(TillApprovals::class);
        $rows = TillApproval::with(['till', 'branch'])->where('status', 'pending')->where('expires_at', '>', now())->latest()->limit(50)->get()
            ->filter(fn (TillApproval $r) => $svc->mayDecide($this->user, $r))->values();

        return ['key' => 'till', 'label' => 'Till requests', 'hint' => 'A cashier is waiting at the till.', 'count' => $rows->count(),
            'url' => '/admin/till-requests', 'inline' => true,
            'items' => $rows->map(fn (TillApproval $r) => [
                'id' => $r->id, 'title' => $r->label(), 'detail' => $r->summary.($r->reason ? ' · '.$r->reason : ''),
                'who' => $r->cashier_name, 'where' => trim(($r->till?->name ?? '').' · '.($r->branch?->name ?? ''), ' ·'),
                'amount' => $r->kind === 'discount' ? null : ($r->amount_minor ?: null), 'percent' => $r->kind === 'discount' ? $r->limit_amount / 100 : null,
                'at' => $r->created_at->toIso8601String(), 'expires_at' => $r->expires_at->toIso8601String(),
                'lines' => collect($r->details ?? [])->map(fn ($d) => trim(($d['qty'] ?? '').' × '.($d['name'] ?? ''), ' ×'))->take(6)->all(),
                'url' => '/admin/till-requests?id='.$r->id,
            ])->all()];
    }

    /** The dashboard's approvals, one group per kind, the documents themselves listed. */
    private function approvals(): array
    {
        $out = [];
        foreach (MyApprovals::KINDS as $key => [$label, $permission, $table, $status, $resource, $branchCol, $askedBy]) {
            try {
                if (! Erp::canAnywhere($permission)) {
                    continue;
                }
                $branches = $branchCol ? Erp::branchIds($permission) : (Erp::branchIds($permission) === null ? null : []);
                if ($branches === []) {
                    continue;
                }
                $q = DB::table($table)->where('tenant_id', $this->user->tenant_id)->where('status', $status)
                    ->when($branchCol && $branches !== null, fn ($w) => $w->whereIn($branchCol, $branches))
                    ->when($askedBy, fn ($w) => $w->where(fn ($x) => $x->whereNull($askedBy)->orWhere($askedBy, '<>', $this->user->id)));
                $count = (clone $q)->count();
                if ($count === 0) {
                    continue;
                }
                $cols = array_flip(Schema::getColumnListing($table));
                $pick = fn (object $r, array $names) => collect($names)->first(fn ($n) => isset($cols[$n]) && filled($r->{$n} ?? null)) !== null
                    ? $r->{collect($names)->first(fn ($n) => isset($cols[$n]) && filled($r->{$n} ?? null))} : null;
                $isPage = ! method_exists($resource, 'canViewAny');
                $list = $isPage ? ($resource::canAccess() ? $resource::getUrl() : null) : ($resource::canViewAny() ? $resource::getUrl('index') : null);
                $open = function (object $r) use ($resource, $isPage, $list) {
                    if ($isPage) {
                        return $list;
                    }
                    foreach (['view', 'edit'] as $p) {
                        if ($resource::hasPage($p)) {
                            return $resource::getUrl($p, ['record' => $r->id]);
                        }
                    }

                    return $list;
                };
                $items = (clone $q)->orderBy(isset($cols['submitted_at']) ? 'submitted_at' : 'created_at')->limit(self::PER_KIND)->get()->map(fn ($r) => [
                    'id' => $r->id,
                    'title' => (string) ($pick($r, ['number']) ?? rtrim($label, 's')),
                    'detail' => mb_substr((string) ($pick($r, ['description', 'reason', 'reason_code']) ?? ''), 0, 160),
                    'who' => $pick($r, ['submitted_by_name', 'created_by_name', 'claimant_name', 'requested_by_name']),
                    'where' => null,
                    'amount' => ($a = $pick($r, ['total_minor', 'amount_minor'])) === null ? null : (int) $a,
                    'percent' => null,
                    'at' => (string) ($pick($r, ['submitted_at', 'created_at']) ?? ''),
                    'expires_at' => null, 'lines' => [],
                    'url' => $this->path((string) $open($r)),
                ])->all();
                $out[] = ['key' => $key, 'label' => $label, 'hint' => 'Open it to approve or turn it down.', 'count' => $count,
                    'url' => $list ? $this->path($list) : null, 'inline' => false, 'items' => $items];
            } catch (Throwable) {
                continue; // a kind that cannot answer for this person is left out, never the whole list
            }
        }

        return $out;
    }

    private function accessRequests(): ?array
    {
        if (! MyApprovals::decidesAccess()) {
            return null;
        }
        $rows = AccessRequest::query()->waiting()->where('requested_by', '<>', $this->user->id)->latest()->limit(self::PER_KIND)->get();
        $count = AccessRequest::query()->waiting()->where('requested_by', '<>', $this->user->id)->count();

        return $count === 0 ? null : ['key' => 'access', 'label' => 'Access requests', 'hint' => 'IT asks; you decide.', 'count' => $count,
            'url' => $this->path(AccessRequestResource::getUrl('index')), 'inline' => false,
            'items' => $rows->map(fn (AccessRequest $r) => ['id' => $r->id, 'title' => AccessRequest::KINDS[$r->kind] ?? $r->kind, 'detail' => mb_substr($r->summary, 0, 200),
                'who' => $r->requested_by_name, 'where' => null, 'amount' => null, 'percent' => null, 'at' => $r->created_at->toIso8601String(),
                'expires_at' => $r->expires_at?->toIso8601String(), 'lines' => [], 'url' => $this->path(AccessRequestResource::getUrl('index'))])->all()];
    }

    private function reviewQueue(): ?array
    {
        if (! Erp::canAnywhere('sync.review')) {
            return null;
        }
        $ids = Erp::branchIds('sync.review');
        $count = ReviewItem::where('status', 'open')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->count();

        return $count === 0 ? null : ['key' => 'review', 'label' => 'Review queue', 'hint' => 'Till work the server could not take as it was: decide what happens.',
            'count' => $count, 'url' => $this->path(ReviewItemResource::getUrl('index')), 'inline' => false, 'items' => []];
    }

    private function timesheets(): ?array
    {
        if (! TimeApprovals::canAccess()) {
            return null;
        }
        $count = TimeApprovals::waiting()->count();

        return $count === 0 ? null : ['key' => 'time', 'label' => 'Timesheets', 'hint' => 'Hours to approve.', 'count' => $count,
            'url' => $this->path(TimeApprovals::getUrl()), 'inline' => false, 'items' => []];
    }

    private function path(string $url): string
    {
        $p = parse_url($url);

        return '/'.ltrim((string) ($p['path'] ?? ''), '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }
}
