<?php

namespace App\Domain\Purchasing;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Http\Controllers\PrintController;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\RfqLine;
use App\Models\RfqQuoteLine;
use App\Models\RfqSupplier;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Requests for quotation (procurement stage 3). The buyer sends one list to several suppliers; each answers on their
 * own secret link with a price per line, how much they can supply, delivery time, credit terms and how long the price
 * holds. Quotes are sealed: nobody in the company sees a price until the closing time passes or the buyer closes the
 * request, so no supplier can be tipped off. The answers are then scored side by side and awarded, whole or line by
 * line, into draft purchase orders that go through approval as usual. A line given to anyone but the cheapest needs
 * the reason written down.
 */
final class RfqService
{
    /** How the comparison weighs an offer, out of 100. */
    public const WEIGHTS = ['price' => 50, 'delivery' => 20, 'terms' => 10, 'record' => 20];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly PurchaseOrderService $orders,
        private readonly Notifier $notifier,
        private readonly SupplierMessages $messages,
    ) {}

    /**
     * @param array{title: string, warehouse_id: string, closes_at: string, needed_by?: ?string, terms?: ?string, purchase_request_id?: ?string,
     *   supplier_ids: list<string>, lines: list<array{product_uom_id: string, qty: string|int|float, note?: ?string}>} $data
     */
    public function create(Actor $actor, array $data): Rfq
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? null);
        $this->require($actor, $warehouse);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new DomainException('Give the request a short title (e.g. "Antimalarials for November").');
        }
        $closes = Carbon::parse($data['closes_at'] ?? 'now');
        if (! $closes->isFuture()) {
            throw new DomainException('The closing time must be in the future: suppliers need time to answer.');
        }
        $suppliers = $this->suppliers(array_unique(array_filter($data['supplier_ids'] ?? [])));
        $lines = $this->lines($data['lines'] ?? []);

        return $this->recorder->transaction(function () use ($actor, $data, $warehouse, $title, $closes, $suppliers, $lines) {
            $rfq = (new Rfq)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'rfq'), 'title' => mb_substr($title, 0, 160),
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'purchase_request_id' => $data['purchase_request_id'] ?? null, 'status' => 'open',
                'closes_at' => $closes, 'needed_by' => ($data['needed_by'] ?? null) ? Carbon::parse($data['needed_by'])->toDateString() : null,
                'terms' => trim((string) ($data['terms'] ?? '')) ?: null, 'created_by' => $actor->user?->id, 'created_by_name' => $actor->name]);
            $this->recorder->save($actor, $rfq, 'rfq.created', count($suppliers).' supplier(s), '.count($lines).' line(s)', $warehouse->branch_id);
            foreach ($lines as $l) {
                RfqLine::create(['rfq_id' => $rfq->id] + $l);
            }
            foreach ($suppliers as $s) {
                RfqSupplier::create(['rfq_id' => $rfq->id, 'supplier_id' => $s->id, 'token' => Str::random(40)]);
            }

            return $rfq->fresh(['lines', 'invitations']);
        });
    }

    /** A buyer asks suppliers for prices on a purchase request instead of ordering straight away. */
    public function fromRequest(Actor $actor, PurchaseRequest $r, array $supplierIds, string $closesAt, ?string $terms = null): Rfq
    {
        if ($r->status !== 'requested') {
            throw new DomainException("{$r->number} is ".strtolower(PurchaseRequest::STATUSES[$r->status] ?? $r->status).'.');
        }
        if ($r->lines->contains(fn ($l) => ! $l->product_uom_id)) {
            throw new DomainException('Quotes are asked for products. Order services and non-stock items from the request directly.');
        }

        return $this->recorder->transaction(function () use ($actor, $r, $supplierIds, $closesAt, $terms) {
            $rfq = $this->create($actor, ['title' => "Purchase request {$r->number}", 'warehouse_id' => $r->warehouse_id, 'closes_at' => $closesAt,
                'needed_by' => $r->needed_by?->toDateString(), 'terms' => $terms, 'purchase_request_id' => $r->id, 'supplier_ids' => $supplierIds,
                'lines' => $r->lines->map(fn ($l) => ['product_uom_id' => $l->product_uom_id, 'qty' => (string) $l->qty, 'note' => $l->note])->all()]);
            $r->forceFill(['status' => 'quoting']);
            $this->recorder->save($actor, $r, 'purchase_request.quoting', "Request for quotation {$rfq->number}", $r->branch_id);

            return $rfq;
        });
    }

    /** Ask one more supplier while the request is open. */
    public function invite(Actor $actor, Rfq $rfq, Supplier $supplier): RfqSupplier
    {
        $this->require($actor, $rfq->warehouse);
        if (! $rfq->acceptsQuotes()) {
            throw new DomainException("{$rfq->number} is no longer taking quotes.");
        }
        if ($rfq->invitations()->where('supplier_id', $supplier->id)->exists()) {
            throw new DomainException("{$supplier->name} is already asked.");
        }
        $this->suppliers([$supplier->id]);

        return $this->recorder->transaction(function () use ($actor, $rfq, $supplier) {
            $inv = RfqSupplier::create(['rfq_id' => $rfq->id, 'supplier_id' => $supplier->id, 'token' => Str::random(40)]);
            $this->recorder->audit->record($actor, 'rfq.supplier_added', $rfq, [], $supplier->name, $rfq->branch_id);

            return $inv;
        });
    }

    /** @return ?string the WhatsApp address to open on this computer (whatsapp_link) */
    public function send(Actor $actor, RfqSupplier $inv, string $channel, string $to, ?string $note = null): ?string
    {
        $rfq = $inv->rfq;
        $this->require($actor, $rfq->warehouse);
        if (! $rfq->acceptsQuotes()) {
            throw new DomainException("{$rfq->number} is no longer taking quotes.");
        }
        $to = trim($to);
        $supplier = Supplier::findOrFail($inv->supplier_id);
        $vars = ['company' => PrintController::companyOf($rfq->tenant_id, $rfq->branch_id)['name'], 'supplier' => $supplier->name, 'number' => $rfq->number,
            'title' => $rfq->title, 'lines' => $rfq->lines()->count(), 'closes' => $rfq->closes_at->format('j M Y H:i'), 'link' => $inv->link(), 'note' => trim((string) $note)];
        $open = $this->messages->send($rfq->tenant_id, 'rfq_invite', $channel, $to, $vars, $rfq->number, $actor->name);

        $this->recorder->transaction(function () use ($actor, $inv, $rfq, $channel, $to, $supplier) {
            $inv->forceFill(['sent_at' => now(), 'sent_via' => $channel, 'sent_to' => mb_substr($to, 0, 160)])->save();
            $this->recorder->audit->record($actor, 'rfq.sent', $rfq, [], OrderSender::CHANNELS[$channel]." to {$supplier->name} ({$to})", $rfq->branch_id);
        });

        return $open;
    }

    /** The supplier opened their link (shown to the buyer as "seen"). */
    public function viewed(RfqSupplier $inv): void
    {
        if (! $inv->viewed_at) {
            $inv->forceFill(['viewed_at' => now()])->save();
        }
    }

    /**
     * A supplier's offer, from their link, or typed in by a buyer from a quote received another way (then $staff is
     * the buyer). Sending again before the closing time replaces the earlier offer.
     *
     * @param array{lines: array<string, array{price?: ?string, qty?: ?string, note?: ?string}>, delivery_days?: ?int, payment_days?: ?int,
     *   valid_until?: ?string, note?: ?string} $data lines keyed by request line id; a line without a price is not offered
     */
    public function quote(RfqSupplier $inv, array $data, string $byName, ?Actor $staff = null): RfqSupplier
    {
        $rfq = $inv->rfq;
        if ($staff) {
            $this->require($staff, $rfq->warehouse);
        }
        if (! $rfq->acceptsQuotes()) {
            throw new DomainException('This request for quotation has closed; prices can no longer be sent.');
        }
        $byName = trim($byName);
        if ($byName === '') {
            throw new DomainException('Give your name.');
        }
        $currency = Tenant::find($rfq->tenant_id)->currency_code;
        $rows = [];
        $total = 0;
        foreach ($rfq->lines as $line) {
            $r = $data['lines'][$line->id] ?? [];
            $price = trim((string) ($r['price'] ?? ''));
            if ($price === '') {
                continue;
            }
            if (! is_numeric($price) || (float) $price <= 0) {
                throw new DomainException("{$line->name}: enter a price above zero, or leave it empty if you do not supply it.");
            }
            $asked = Qty::toMilli((string) $line->qty);
            $qtyIn = trim((string) ($r['qty'] ?? ''));
            $qty = $qtyIn === '' ? $asked : Qty::toMilli($qtyIn);
            if ($qty <= 0 || $qty > $asked) {
                throw new DomainException("{$line->name}: the quantity you can supply must be above zero and at most ".Qty::format($asked)." {$line->uom_code}.");
            }
            $unit = Money::toMinor($price, $currency);
            $rows[] = ['rfq_line_id' => $line->id, 'unit_cost_minor' => $unit, 'qty' => Qty::format($qty), 'note' => ($r['note'] ?? null) ? mb_substr(trim($r['note']), 0, 255) : null];
            $total += Qty::divRound($qty * $unit, 1000);
        }
        if ($rows === []) {
            throw new DomainException('Enter a price for at least one line.');
        }
        $validUntil = ($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until']) : null;
        if ($validUntil && $validUntil->isBefore(today())) {
            throw new DomainException('The date your prices hold until has already passed.');
        }
        foreach (['delivery_days' => 365, 'payment_days' => 365] as $f => $max) {
            if (($data[$f] ?? null) !== null && $data[$f] !== '' && ((int) $data[$f] < 0 || (int) $data[$f] > $max)) {
                throw new DomainException('Days must be between 0 and 365.');
            }
        }
        $supplier = Supplier::withoutGlobalScopes()->findOrFail($inv->supplier_id);
        $actor = $staff ?? Actor::system($rfq->tenant_id, mb_substr("{$byName} ({$supplier->name})", 0, 120));
        $who = $staff ? mb_substr("Entered by {$staff->name}".($byName !== $staff->name ? " from {$byName}" : ''), 0, 255) : mb_substr($byName, 0, 255);
        $int = fn ($v) => ($v === null || $v === '') ? null : (int) $v;

        return $this->recorder->transaction(function () use ($actor, $inv, $rfq, $rows, $total, $data, $validUntil, $who, $int, $supplier) {
            $again = $inv->status === 'quoted';
            RfqQuoteLine::where('rfq_supplier_id', $inv->id)->delete();
            foreach ($rows as $row) {
                RfqQuoteLine::create(['rfq_supplier_id' => $inv->id] + $row);
            }
            $inv->forceFill(['status' => 'quoted', 'quoted_at' => now(), 'quoted_by_name' => $who, 'delivery_days' => $int($data['delivery_days'] ?? null),
                'payment_days' => $int($data['payment_days'] ?? null), 'valid_until' => $validUntil?->toDateString(),
                'note' => ($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 500) : null, 'total_minor' => $total])->save();
            // Sealed: the record says who quoted and when, never the price, until the request closes.
            $this->recorder->audit->record($actor, $again ? 'rfq.quote_changed' : 'rfq.quoted', $rfq, [], "{$supplier->name}: ".count($rows).' line(s)', $rfq->branch_id);
            $this->notifier->toPermission($rfq->tenant_id, 'purchasing.manage', $rfq->branch_id, "Quote from {$supplier->name} on {$rfq->number}",
                'Prices stay sealed until '.$rfq->closes_at->format('j M H:i').' or until you close the request.', url('/admin/rfqs/'.$rfq->id), topic: 'purchasing');

            return $inv->fresh('quoteLines');
        });
    }

    public function decline(RfqSupplier $inv, string $reason, string $byName): RfqSupplier
    {
        $rfq = $inv->rfq;
        if (! $rfq->acceptsQuotes()) {
            throw new DomainException('This request for quotation has closed.');
        }
        $supplier = Supplier::withoutGlobalScopes()->findOrFail($inv->supplier_id);
        $actor = Actor::system($rfq->tenant_id, mb_substr(trim($byName) ?: $supplier->name, 0, 120)." ({$supplier->name})");

        return $this->recorder->transaction(function () use ($actor, $inv, $rfq, $reason, $supplier) {
            RfqQuoteLine::where('rfq_supplier_id', $inv->id)->delete();
            $inv->forceFill(['status' => 'declined', 'quoted_at' => now(), 'quoted_by_name' => $actor->name, 'note' => mb_substr(trim($reason), 0, 500) ?: null,
                'total_minor' => null])->save();
            $this->recorder->audit->record($actor, 'rfq.declined', $rfq, [], "{$supplier->name}: ".(trim($reason) ?: 'no reason given'), $rfq->branch_id);

            return $inv;
        });
    }

    /** Stop taking quotes now and open the prices for comparison. */
    public function close(Actor $actor, Rfq $rfq): Rfq
    {
        $this->require($actor, $rfq->warehouse);
        if ($rfq->status !== 'open') {
            throw new DomainException("{$rfq->number} is ".strtolower(Rfq::STATUSES[$rfq->status]).'.');
        }
        $rfq->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_name' => $actor->name]);

        return $this->recorder->save($actor, $rfq, 'rfq.closed', $rfq->closes_at->isFuture() ? 'Closed before the closing time' : null, $rfq->branch_id);
    }

    public function cancel(Actor $actor, Rfq $rfq, string $reason): Rfq
    {
        $this->require($actor, $rfq->warehouse);
        if (! in_array($rfq->status, ['open', 'closed'], true)) {
            throw new DomainException("{$rfq->number} is ".strtolower(Rfq::STATUSES[$rfq->status]).'.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give the reason.');
        }

        return $this->recorder->transaction(function () use ($actor, $rfq, $reason) {
            $rfq->forceFill(['status' => 'cancelled', 'cancelled_reason' => mb_substr(trim($reason), 0, 255), 'closed_at' => $rfq->closed_at ?? now(),
                'closed_by_name' => $rfq->closed_by_name ?? $actor->name]);
            $this->recorder->save($actor, $rfq, 'rfq.cancelled', $reason, $rfq->branch_id);
            $r = $rfq->purchase_request_id ? PurchaseRequest::find($rfq->purchase_request_id) : null;
            if ($r?->status === 'quoting') { // back to the buyers' list
                $r->forceFill(['status' => 'requested']);
                $this->recorder->save($actor, $r, 'purchase_request.requested', "Request for quotation {$rfq->number} cancelled", $r->branch_id);
            }

            return $rfq;
        });
    }

    /**
     * The offers side by side. Each offer is scored out of 100: price 50 (per line, the lowest price over theirs,
     * a line not offered scores 0, a part quantity scores its share), delivery 20 (fastest over theirs), credit
     * terms 10 (theirs over the longest), past performance 20 (their scorecard; a new supplier gets half).
     *
     * @return array{offers: array<string, array{invitation: RfqSupplier, name: string, score: int, parts: array<string, float>, lines: int,
     *   expired: bool, record: ?int, grade: ?string}>, lines: array<string, array{line: RfqLine, quotes: array<string, RfqQuoteLine>, cheapest: ?string}>, best: ?string}
     */
    public function compare(Rfq $rfq): array
    {
        if (! $rfq->isUnsealed()) {
            throw new DomainException('The prices are sealed until '.$rfq->closes_at->format('j M Y H:i').'. Close the request to see them now.');
        }
        $offers = $rfq->invitations()->with(['quoteLines', 'supplier'])->where('status', 'quoted')->get()->keyBy('id');
        $lines = [];
        foreach ($rfq->lines as $line) {
            $quotes = [];
            foreach ($offers as $id => $inv) {
                if ($q = $inv->quoteLines->firstWhere('rfq_line_id', $line->id)) {
                    $quotes[$id] = $q;
                }
            }
            $cheapest = $quotes ? collect($quotes)->sortBy(fn ($q) => [$q->unit_cost_minor, -(float) $q->qty])->keys()->first() : null;
            $lines[$line->id] = ['line' => $line, 'quotes' => $quotes, 'cheapest' => $cheapest];
        }
        $fastest = $offers->pluck('delivery_days')->filter(fn ($d) => $d !== null)->min();
        $longest = (int) $offers->pluck('payment_days')->filter(fn ($d) => $d !== null)->max();
        $out = [];
        foreach ($offers as $id => $inv) {
            $price = 0.0;
            foreach ($lines as $l) {
                $q = $l['quotes'][$id] ?? null;
                if ($q) {
                    $low = $l['quotes'][$l['cheapest']]->unit_cost_minor;
                    $price += ($low / max(1, $q->unit_cost_minor)) * (Qty::toMilli((string) $q->qty) / max(1, Qty::toMilli((string) $l['line']->qty)));
                }
            }
            $card = SupplierScorecard::for($inv->supplier);
            $parts = [
                'price' => self::WEIGHTS['price'] * $price / max(1, count($lines)),
                'delivery' => $inv->delivery_days === null ? 0.0 : self::WEIGHTS['delivery'] * ($fastest + 1) / ($inv->delivery_days + 1),
                'terms' => $longest === 0 ? (float) self::WEIGHTS['terms'] : ($inv->payment_days === null ? 0.0 : self::WEIGHTS['terms'] * $inv->payment_days / $longest),
                'record' => self::WEIGHTS['record'] * ($card['score'] ?? 50) / 100,
            ];
            $out[$id] = ['invitation' => $inv, 'name' => $inv->supplier->name, 'score' => (int) round(array_sum($parts)), 'parts' => array_map(fn ($p) => round($p, 1), $parts),
                'lines' => $inv->quoteLines->count(), 'expired' => $inv->valid_until !== null && $inv->valid_until->isBefore(today()),
                'record' => $card['score'], 'grade' => $card['grade']];
        }
        uasort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return ['offers' => $out, 'lines' => $lines, 'best' => array_key_first($out)];
    }

    /**
     * Award: each line to the offer chosen for it (null = not bought now). One draft purchase order per supplier at
     * their prices, expected after their delivery time. A line not given to the cheapest price, or an offer whose
     * prices have expired, needs the reason.
     *
     * @param  array<string, ?string>  $awards  request line id => invitation id
     * @return list<PurchaseOrder>
     */
    public function award(Actor $actor, Rfq $rfq, array $awards, ?string $note = null): array
    {
        $this->require($actor, $rfq->warehouse);
        if (! in_array($rfq->status, ['open', 'closed'], true)) {
            throw new DomainException("{$rfq->number} is ".strtolower(Rfq::STATUSES[$rfq->status]).'.');
        }
        $cmp = $this->compare($rfq);
        $chosen = [];
        $needsReason = [];
        foreach (array_filter($awards) as $lineId => $invId) {
            $l = $cmp['lines'][$lineId] ?? throw new DomainException('That line is not on this request.');
            $q = $l['quotes'][$invId] ?? throw new DomainException("{$l['line']->name}: that supplier did not quote for it.");
            $offer = $cmp['offers'][$invId];
            if ($invId !== $l['cheapest'] && $q->unit_cost_minor > $l['quotes'][$l['cheapest']]->unit_cost_minor) {
                $needsReason[] = "{$l['line']->name} is not going to the cheapest price";
            }
            if ($offer['expired']) {
                $needsReason[] = "the prices from {$offer['name']} have expired";
            }
            $chosen[$invId][] = [$l['line'], $q];
        }
        if ($chosen === []) {
            throw new DomainException('Choose a supplier for at least one line.');
        }
        $note = trim((string) $note);
        if ($needsReason && $note === '') {
            throw new DomainException('Give the reason: '.implode('; ', array_unique($needsReason)).'.');
        }
        $currency = Tenant::find($rfq->tenant_id)->currency_code;

        return $this->recorder->transaction(function () use ($actor, $rfq, $cmp, $chosen, $note, $currency) {
            $pos = [];
            foreach ($chosen as $invId => $rows) {
                $inv = $cmp['offers'][$invId]['invitation'];
                $expected = $inv->delivery_days !== null ? today()->addDays($inv->delivery_days)->toDateString() : $rfq->needed_by?->toDateString();
                $po = $this->orders->saveDraft($actor, new PurchaseOrder, ['supplier_id' => $inv->supplier_id, 'warehouse_id' => $rfq->warehouse_id, 'expected_on' => $expected,
                    'note' => "From request for quotation {$rfq->number}, quote of ".$inv->quoted_at->format('j M Y').($inv->payment_days !== null ? ", {$inv->payment_days} days credit" : ''),
                    'lines' => array_map(fn ($r) => ['product_uom_id' => $r[0]->product_uom_id, 'qty' => (string) $r[1]->qty,
                        'unit_cost' => Money::format($r[1]->unit_cost_minor, $currency)], $rows)]);
                $po->forceFill(['rfq_id' => $rfq->id]);
                $this->recorder->save($actor, $po, 'purchase_order.from_rfq', $rfq->number, $po->branch_id);
                RfqQuoteLine::whereIn('id', array_map(fn ($r) => $r[1]->id, $rows))->update(['awarded' => true]);
                $inv->forceFill(['purchase_order_id' => $po->id])->save();
                $pos[] = $po;
            }
            $rfq->forceFill(['status' => 'awarded', 'awarded_by' => $actor->user?->id, 'awarded_by_name' => $actor->name, 'awarded_at' => now(),
                'award_note' => $note !== '' ? mb_substr($note, 0, 500) : null, 'closed_at' => $rfq->closed_at ?? now(), 'closed_by_name' => $rfq->closed_by_name ?? $actor->name]);
            $this->recorder->save($actor, $rfq, 'rfq.awarded', implode(', ', array_map(fn ($p) => $p->number, $pos)).($note !== '' ? ". {$note}" : ''), $rfq->branch_id);
            $r = $rfq->purchase_request_id ? PurchaseRequest::find($rfq->purchase_request_id) : null;
            if ($r?->status === 'quoting') {
                $r->forceFill(['status' => 'ordered', 'purchase_order_id' => $pos[0]->id, 'closed_at' => now(), 'closed_by_name' => $actor->name]);
                $this->recorder->save($actor, $r, 'purchase_request.ordered', "Request for quotation {$rfq->number}: ".implode(', ', array_map(fn ($p) => $p->number, $pos)), $r->branch_id);
            }

            return $pos;
        });
    }

    /** @return list<Supplier> */
    private function suppliers(array $ids): array
    {
        if ($ids === []) {
            throw new DomainException('Choose the suppliers to ask (three or more gives a fair comparison).');
        }
        $out = [];
        foreach ($ids as $id) {
            $s = Supplier::findOrFail($id);
            if (! $s->is_active) {
                throw new DomainException("{$s->name} is inactive.");
            }
            app(SupplierOnboarding::class)->assertCanOrder($s); // only suppliers the company may buy from are asked
            $out[] = $s;
        }

        return $out;
    }

    private function lines(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $unit = ProductUom::with('uom')->find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose a product and unit on every line.');
            $product = Product::findOrFail($unit->product_id);
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException("{$product->name}: enter a quantity above zero.");
            }
            if (! $unit->is_purchasable) {
                throw new DomainException("{$product->name} is not bought in {$unit->uom->code}: choose its buying unit (e.g. the box).");
            }
            $out[] = ['line_no' => $i + 1, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => $product->name,
                'uom_code' => $unit->uom->code, 'qty' => Qty::format($qty), 'note' => ($r['note'] ?? null) ? mb_substr(trim($r['note']), 0, 255) : null];
        }
        if ($out === []) {
            throw new DomainException('Add at least one line.');
        }

        return $out;
    }

    public function can(Actor $actor, ?Warehouse $w = null): bool
    {
        return $actor->user !== null && ($w ? $this->authz->allows($actor->user, 'purchasing.manage', $w) : $this->authz->allowsAnywhere($actor->user, 'purchasing.manage'));
    }

    private function require(Actor $actor, Warehouse $w): void
    {
        if (! $this->can($actor, $w)) {
            throw new AccessDenied;
        }
    }
}
