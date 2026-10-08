<?php

namespace App\Domain\Services;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServicePass;
use App\Models\ServicePassUse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prepaid sessions (owner decision 2026-10-19: both prepaid packs and recurring memberships). A pack product sold at the
 * till gives the customer one pass per unit; a membership gives one pass per billing period. A pass lists its items —
 * "10 sessions of any massage", "2 facials" — and each session used is a line of that service at no charge, written to
 * the append-only uses. A return gives a used session back, or voids an unused pack.
 *
 * Revenue: the pack is income when it is sold (like any sale); the sessions used later are free lines. The value of a
 * session (the pack's price before tax ÷ its sessions) is kept only as the commission base for the staff who do them.
 */
final class PassService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * The items of a pack or plan, checked: each names the services it can be used for and how many sessions.
     *
     * @return list<array{label: string, product_ids: list<string>, sessions: ?int}>
     */
    public static function normaliseItems(array $items, bool $unlimitedAllowed): array
    {
        $out = [];
        foreach (array_values($items) as $it) {
            $ids = array_values(array_unique(array_filter(array_map('strval', (array) ($it['product_ids'] ?? [])))));
            $sessions = ($it['sessions'] ?? null) === null || $it['sessions'] === '' ? null : (int) $it['sessions'];
            if ($ids === [] && $sessions === null && ! $unlimitedAllowed) {
                continue; // an empty repeater row
            }
            $products = Product::whereIn('id', $ids)->pluck('name', 'id');
            if ($ids === [] || $products->count() !== count($ids)) {
                throw new DomainException('Each item of a pack or plan names the services it can be used for.');
            }
            if ($sessions === null ? ! $unlimitedAllowed : ($sessions < 1 || $sessions > 1000)) {
                throw new DomainException($unlimitedAllowed ? 'Sessions: 1 to 1000, or empty for unlimited.' : 'Sessions: 1 to 1000.');
            }
            $label = trim((string) ($it['label'] ?? '')) ?: $products->first().(count($ids) > 1 ? ' (or similar)' : '');
            $out[] = ['label' => mb_substr($label, 0, 120), 'product_ids' => $ids, 'sessions' => $sessions];
        }

        return $out;
    }

    /** A pack definition for a product (null = not a pack). @return ?array{items: list<array>, valid_days: ?int} */
    public static function normalisePack(mixed $pack): ?array
    {
        if (! is_array($pack) || ($pack['items'] ?? []) === []) {
            return null;
        }
        $items = self::normaliseItems($pack['items'], false);
        if ($items === []) {
            return null;
        }
        $days = ($pack['valid_days'] ?? null) === null || $pack['valid_days'] === '' ? null : (int) $pack['valid_days'];
        if ($days !== null && ($days < 1 || $days > 3650)) {
            throw new DomainException('A pack is valid for 1 to 3650 days, or leave it empty for no expiry.');
        }

        return ['items' => $items, 'valid_days' => $days];
    }

    /** Sessions left per item (null = unlimited) — what was given less what was used. */
    public static function remaining(ServicePass $pass): array
    {
        $used = ServicePassUse::where('service_pass_id', $pass->id)->groupBy('item')->selectRaw('item, SUM(sessions) AS n')->pluck('n', 'item');

        return array_map(fn ($it, $i) => $it['sessions'] === null ? null : $it['sessions'] - (int) ($used[$i] ?? 0), $pass->items, array_keys($pass->items));
    }

    public static function isUsable(ServicePass $pass, Carbon $at): bool
    {
        return $pass->status === 'active' && $pass->valid_from->lte($at) && (! $pass->expires_on || $pass->expires_on->copy()->endOfDay()->gte($at));
    }

    /** A customer's passes that can still be used, with what is left. */
    public static function forCustomer(string $customerId, ?Carbon $at = null): array
    {
        $at ??= now();

        return ServicePass::where('customer_id', $customerId)->where('status', 'active')->orderBy('expires_on')->get()
            ->filter(fn (ServicePass $p) => self::isUsable($p, $at))
            ->map(fn (ServicePass $p) => ['pass' => $p, 'remaining' => self::remaining($p)])
            ->filter(fn ($x) => collect($x['remaining'])->contains(fn ($n) => $n === null || $n > 0))->values()->all();
    }

    /** Give a customer a pass (inside the caller's transaction for a sale or a membership bill). */
    public function issue(Actor $actor, Customer $customer, string $branchId, string $source, string $name, array $items, Carbon $from, ?Carbon $expires,
        int $sessionValue, array $link = []): ServicePass
    {
        return $this->recorder->transaction(function () use ($actor, $customer, $branchId, $source, $name, $items, $from, $expires, $sessionValue, $link) {
            $pass = (new ServicePass)->forceFill(['tenant_id' => $customer->tenant_id, 'number' => $this->numbers->next($customer->tenant_id, 'service_pass'),
                'customer_id' => $customer->id, 'branch_id' => $branchId, 'source' => $source, 'name' => mb_substr($name, 0, 160), 'items' => $items,
                'session_value_minor' => max(0, $sessionValue), 'valid_from' => $from->toDateString(), 'expires_on' => $expires?->toDateString(), 'status' => 'active'] + $link);

            return $this->recorder->save($actor, $pass, 'service_pass.issued', branchId: $branchId);
        });
    }

    /**
     * A till line paid with a session from a pass: the pass is the customer's, usable now, covers the service and has
     * sessions left. Online a problem refuses the sale; offline (the till checked its last copy) it becomes a review item.
     *
     * @return array{pass: ServicePass, item: int}|null
     */
    public function forLine(array $l, ?Customer $customer, Product $product, int $qtyMilli, Carbon $at, bool $offline, array &$flags): ?array
    {
        if (empty($l['service_pass_id'])) {
            return null;
        }
        $pass = ServicePass::find($l['service_pass_id']);
        $item = (int) ($l['pass_item'] ?? 0);
        $problem = match (true) {
            ! $pass => 'that pass is not known here',
            ! $customer || $pass->customer_id !== $customer->id => "pass {$pass->number} belongs to another customer",
            ! self::isUsable($pass, $at) => "pass {$pass->number} is void or expired",
            ! isset($pass->items[$item]) || ! in_array($product->id, $pass->items[$item]['product_ids'], true) => "pass {$pass->number} does not cover {$product->name}",
            $qtyMilli % 1000 !== 0 => 'sessions are whole numbers',
            default => null,
        };
        if ($problem && ! $offline) {
            throw new DomainException(ucfirst($problem).'.', 'pass_invalid');
        }
        if ($problem) {
            $flags[] = ['pass_conflict', "Session used offline but {$problem}"];

            return $pass && isset($pass->items[$item]) ? ['pass' => $pass, 'item' => $item] : null;
        }

        return ['pass' => $pass, 'item' => $item];
    }

    /**
     * Inside the sale's transaction: the sessions used on its lines (checked again under a lock, so two tills cannot
     * spend the last session twice), and the passes for packs it sold.
     */
    public function settleSale(Actor $actor, Sale $sale, ?Customer $customer, bool $offline, array &$flags): void
    {
        $lines = DB::table('sale_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.sale_id', $sale->id)
            ->where(fn ($q) => $q->whereNotNull('l.service_pass_id')->orWhereNotNull('p.pack'))
            ->orderBy('l.line_no')->get(['l.id', 'l.product_id', 'l.name', 'l.qty', 'l.net_minor', 'l.service_pass_id', 'l.pass_item', 'p.pack']);
        foreach ($lines->whereNotNull('service_pass_id') as $l) {
            $pass = ServicePass::lockForUpdate()->find($l->service_pass_id);
            if (! $pass) {
                continue; // flagged when the line was checked
            }
            $n = intdiv(Qty::toMilli((string) $l->qty), 1000);
            $remaining = self::remaining($pass);
            $left = array_key_exists((int) $l->pass_item, $remaining) ? $remaining[(int) $l->pass_item] : 0; // null = unlimited
            if ($left !== null && $left < $n) {
                $offline ? $flags[] = ['pass_conflict', "Pass {$pass->number}: {$n} session(s) used offline with {$left} left"]
                    : throw new DomainException("Pass {$pass->number} has {$left} session(s) left.", 'pass_invalid');
            }
            $this->use($actor, $pass, (int) $l->pass_item, $l->product_id, $n, $sale, $l->id);
        }

        foreach ($lines->whereNotNull('pack') as $l) {
            if ($l->service_pass_id) {
                continue;
            }
            $pack = json_decode($l->pack, true);
            if (! $customer) {
                $offline ? $flags[] = ['pack_without_customer', "{$l->name} sold offline without a customer: no pass was given"]
                    : throw new DomainException("{$l->name} is a prepaid pack: choose the customer it belongs to.", 'customer_required');

                continue;
            }
            $units = intdiv(Qty::toMilli((string) $l->qty), 1000);
            $sessions = array_sum(array_map(fn ($it) => (int) $it['sessions'], $pack['items']));
            $value = $units > 0 && $sessions > 0 ? Qty::divRound((int) $l->net_minor, $units * $sessions) : 0;
            $from = $sale->occurred_at->copy();
            for ($i = 0; $i < $units; $i++) {
                $this->issue($actor, $customer, $sale->branch_id, 'pack', $l->name, $pack['items'], $from,
                    ! empty($pack['valid_days']) ? $from->copy()->addDays((int) $pack['valid_days']) : null, $value, ['sale_id' => $sale->id, 'sale_line_id' => $l->id]);
            }
        }
    }

    /**
     * Inside a return's transaction: sessions on the lines coming back are given back; packs coming back void passes that
     * were not used (a pack with sessions used cannot be returned at the till).
     */
    public function settleReturn(Actor $actor, Sale $return): void
    {
        $lines = DB::table('sale_lines as r')->join('sale_lines as o', 'o.id', '=', 'r.original_line_id')->join('products as p', 'p.id', '=', 'r.product_id')
            ->where('r.sale_id', $return->id)->where(fn ($q) => $q->whereNotNull('o.service_pass_id')->orWhereNotNull('p.pack'))
            ->get(['r.id', 'r.qty', 'r.name', 'r.product_id', 'o.id as original_id', 'o.service_pass_id', 'o.pass_item']);
        foreach ($lines as $l) {
            $n = intdiv(abs(Qty::toMilli((string) $l->qty)), 1000);
            if ($l->service_pass_id) {
                $this->use($actor, ServicePass::lockForUpdate()->findOrFail($l->service_pass_id), (int) $l->pass_item, $l->product_id, -$n, $return, $l->id);

                continue;
            }
            $unused = ServicePass::where('sale_line_id', $l->original_id)->where('status', 'active')->lockForUpdate()->get()
                ->filter(fn (ServicePass $p) => ! ServicePassUse::where('service_pass_id', $p->id)->exists())->take($n);
            if ($unused->count() < $n) {
                throw new DomainException("{$l->name}: sessions of this pack were already used, so it cannot be returned at the till. A manager can void the pass and refund what is fair.", 'pass_used');
            }
            foreach ($unused as $p) {
                $p->forceFill(['status' => 'void', 'void_reason' => "Returned on {$return->receipt_no}"]);
                $this->recorder->save($actor, $p, 'service_pass.voided', branchId: $return->branch_id);
            }
        }
    }

    /** Void a pass by hand (sold in error, refunded outside the till). Uses stay as history. */
    public function void(Actor $actor, ServicePass $pass, string $reason): ServicePass
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'services.passes', Branch::findOrFail($pass->branch_id))) {
            throw new AccessDenied;
        }
        if ($pass->status !== 'active' || trim($reason) === '') {
            throw new DomainException($pass->status !== 'active' ? 'That pass is already void.' : 'Give the reason.');
        }
        $pass->forceFill(['status' => 'void', 'void_reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->save($actor, $pass, 'service_pass.voided', $reason, $pass->branch_id);
    }

    private function use(Actor $actor, ServicePass $pass, int $item, string $productId, int $sessions, Sale $sale, string $lineId): void
    {
        $row = (new ServicePassUse)->forceFill(['id' => (string) Str::uuid7(), 'tenant_id' => $pass->tenant_id, 'service_pass_id' => $pass->id, 'item' => $item,
            'product_id' => $productId, 'sessions' => $sessions, 'branch_id' => $sale->branch_id, 'sale_id' => $sale->id, 'sale_line_id' => $lineId,
            'by_name' => mb_substr($actor->name, 0, 120), 'occurred_at' => $sale->occurred_at, 'created_at' => now()]);
        $row->save();
        $this->recorder->outbox->publish($actor, $row, $sessions > 0 ? 'service_pass.used' : 'service_pass.given_back');
    }
}
