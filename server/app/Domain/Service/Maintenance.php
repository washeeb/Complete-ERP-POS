<?php

namespace App\Domain\Service;

use App\Domain\Inventory\StockLedger;
use App\Domain\Messaging\Notifier;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Sales\InvoiceService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePart;
use App\Models\MaintenancePlan;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\ProjectRate;
use App\Models\SupplierBill;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;

/**
 * Maintenance (paid add-on "service"; interface stage 4).
 *
 * Assets: the company's own equipment (generator, fridge, van, tills…, linked to its fixed-asset record) and customers'
 * equipment (sold or serviced: warranty, service contract). Plans bring servicing round every so many days or so many
 * hours / km on the meter: a work order opens by itself a few days early. Work orders — planned, breakdown or a visit
 * to a customer — collect parts from stock (at cost), technicians' time (valued at the company's hourly cost or the
 * person's rate) and outside technicians (supplier bill lines charged to the order), and record downtime.
 *
 * The books: parts for the company's own equipment go to Repairs and maintenance; for a customer's under warranty or
 * contract to Warranty and after-sales costs; for a chargeable customer job to cost of sales (the job is invoiced).
 * A sensor alarm (stage 5) calls breakdown() to open an urgent work order on the asset.
 */
final class Maintenance
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $stock,
        private readonly ServiceDesk $desk,
        private readonly Notifier $notifier,
    ) {}

    // --- assets and plans ------------------------------------------------------------------------------------------------

    public function saveAsset(Actor $actor, MaintenanceAsset $a, array $d): MaintenanceAsset
    {
        $this->require($actor, 'maintenance.manage');
        $owner = ($d['owner'] ?? $a->owner ?? 'own') === 'customer' ? 'customer' : 'own';
        $name = trim((string) ($d['name'] ?? $a->name));
        if ($name === '') {
            throw new DomainException('Name the equipment (e.g. “Generator 20 kVA, back yard”).');
        }
        $customer = array_key_exists('customer_id', $d) ? ($d['customer_id'] ?: null) : $a->customer_id;
        if ($owner === 'customer' && ! $customer) {
            throw new DomainException('Choose the customer the equipment belongs to.');
        }
        $branch = array_key_exists('branch_id', $d) ? ($d['branch_id'] ?: null) : $a->branch_id;
        if ($owner === 'own' && ! $branch) {
            throw new DomainException('Choose the branch the equipment is at.');
        }
        $unit = $d['meter_unit'] ?? $a->meter_unit;
        $keep = fn (string $k) => array_key_exists($k, $d) ? ($d[$k] === '' ? null : $d[$k]) : $a->{$k};

        return $this->recorder->transaction(function () use ($actor, $a, $owner, $name, $customer, $branch, $unit, $keep, $d) {
            $isNew = ! $a->exists;
            $a->forceFill([
                'name' => mb_substr($name, 0, 255), 'owner' => $owner, 'customer_id' => $owner === 'customer' ? $customer : null, 'branch_id' => $branch,
                'category' => isset(MaintenanceAsset::CATEGORIES[$d['category'] ?? '']) ? $d['category'] : ($a->category ?? 'other'),
                'location' => $keep('location'), 'make' => $keep('make'), 'model' => $keep('model'), 'serial_no' => $keep('serial_no'),
                'product_id' => $keep('product_id'), 'supplier_id' => $keep('supplier_id'), 'fixed_asset_id' => $owner === 'own' ? $keep('fixed_asset_id') : null,
                'acquired_on' => $keep('acquired_on'), 'warranty_until' => $keep('warranty_until'), 'contract_until' => $keep('contract_until'),
                'meter_unit' => in_array($unit, ['hours', 'km'], true) ? $unit : null, 'notes' => $keep('notes'),
                'status' => isset(MaintenanceAsset::STATUSES[$d['status'] ?? '']) ? $d['status'] : ($a->status ?? 'working'),
            ]);
            if ($isNew) {
                $a->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'maintenance_asset')]);
            }

            return $this->recorder->save($actor, $a, $isNew ? 'asset.registered' : 'asset.updated', branchId: $a->branch_id);
        });
    }

    /** A new meter reading (generator hours, km): plans by use may now be due. */
    public function meter(Actor $actor, MaintenanceAsset $a, float $reading): MaintenanceAsset
    {
        $this->require($actor, 'maintenance.work');
        if (! $a->meter_unit) {
            throw new DomainException('This equipment has no meter.');
        }
        if ($a->meter_reading !== null && $reading < (float) $a->meter_reading) {
            throw new DomainException('The reading is lower than the last one ('.$a->meter_reading.').');
        }
        $a->meter_reading = $reading;

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $a, 'asset.meter', (string) $reading));
    }

    public function savePlan(Actor $actor, MaintenanceAsset $a, MaintenancePlan $p, array $d): MaintenancePlan
    {
        $this->require($actor, 'maintenance.manage');
        $days = ($d['every_days'] ?? null) ? (int) $d['every_days'] : null;
        $meter = ($d['every_meter'] ?? null) ? (float) $d['every_meter'] : null;
        if (! $days && ! $meter) {
            throw new DomainException('Say how often: every so many days, or every so many '.($a->meter_unit ?? 'hours / km').'.');
        }
        if ($meter && ! $a->meter_unit) {
            throw new DomainException('This equipment has no meter: plan it by days.');
        }
        $name = trim((string) ($d['name'] ?? $p->name));
        if ($name === '') {
            throw new DomainException('Name the servicing (e.g. “Oil and filter change”).');
        }
        $last = ($d['last_done_on'] ?? null) ? CarbonImmutable::parse($d['last_done_on']) : ($p->last_done_on ? CarbonImmutable::parse($p->last_done_on) : null);
        $checklist = array_values(array_filter(array_map(fn ($i) => trim((string) (is_array($i) ? ($i['item'] ?? '') : $i)), (array) ($d['checklist'] ?? $p->checklist ?? []))));

        return $this->recorder->transaction(function () use ($actor, $a, $p, $d, $days, $meter, $name, $last, $checklist) {
            $p->forceFill(['tenant_id' => $actor->tenantId, 'asset_id' => $a->id, 'name' => mb_substr($name, 0, 255), 'every_days' => $days, 'every_meter' => $meter,
                'checklist' => $checklist, 'assigned_to' => ($d['assigned_to'] ?? $p->assigned_to) ?: null, 'lead_days' => (int) ($d['lead_days'] ?? $p->lead_days ?? 7),
                'last_done_on' => $last?->toDateString(), 'last_meter' => array_key_exists('last_meter', $d) ? ($d['last_meter'] ?: null) : ($p->last_meter ?? $a->meter_reading),
                'is_active' => (bool) ($d['is_active'] ?? $p->is_active ?? true),
                'next_due_on' => $days ? ($last ?? CarbonImmutable::today())->addDays($days)->toDateString() : null]);

            return $this->recorder->save($actor, $p, 'maintenance_plan.saved', branchId: $a->branch_id);
        });
    }

    /** Every few minutes: plans now due (by date, with their lead days, or by the meter) get a work order, once. */
    public function duePlans(string $tenantId): int
    {
        $n = 0;
        foreach (MaintenancePlan::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->with('asset')->get() as $p) {
            $a = $p->asset;
            if (! $a || $a->status === 'retired' || MaintenanceOrder::withoutGlobalScopes()->where('plan_id', $p->id)->whereIn('status', ['open', 'working', 'waiting'])->exists()) {
                continue;
            }
            $byDate = $p->next_due_on && $p->next_due_on->copy()->subDays($p->lead_days)->lte(today());
            $byMeter = $p->every_meter && $a->meter_reading !== null && (float) $a->meter_reading >= (float) ($p->last_meter ?? 0) + (float) $p->every_meter;
            if (! $byDate && ! $byMeter) {
                continue;
            }
            $actor = Actor::system($tenantId, 'Maintenance plans');
            $this->recorder->transaction(fn () => $this->create($actor, $a, ['kind' => $a->owner === 'customer' ? 'visit' : 'planned', 'title' => $p->name, 'plan_id' => $p->id,
                'assigned_to' => $p->assigned_to, 'due_on' => $p->next_due_on?->toDateString() ?? today()->toDateString(), 'source' => 'plan',
                'checklist' => array_map(fn ($i) => ['item' => $i, 'done' => false], $p->checklist ?? [])]));
            $n++;
        }

        return $n;
    }

    // --- work orders -----------------------------------------------------------------------------------------------------

    public function open(Actor $actor, MaintenanceAsset $a, array $d): MaintenanceOrder
    {
        $this->require($actor, 'maintenance.work', $a->branch);

        return $this->recorder->transaction(fn () => $this->create($actor, $a, $d));
    }

    /**
     * Something stopped working (a person, a ticket, or a sensor alarm): an urgent breakdown work order, the asset
     * marked down. A second alarm while one is open adds to it instead.
     */
    public function breakdown(string $tenantId, string $assetId, string $title, ?string $details = null, string $source = 'sensor'): MaintenanceOrder
    {
        $a = MaintenanceAsset::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($assetId);
        $actor = Actor::system($tenantId, $source === 'sensor' ? 'Sensor alarm' : 'Breakdown', $source);

        return $this->recorder->transaction(function () use ($actor, $a, $title, $details, $source) {
            $open = MaintenanceOrder::withoutGlobalScopes()->where('asset_id', $a->id)->where('kind', 'breakdown')->whereIn('status', ['open', 'working', 'waiting'])->first();
            if ($open) {
                $open->details = trim(($open->details ? $open->details."\n" : '').now()->format('j M H:i').' '.$title.($details ? ": {$details}" : ''));

                return $this->recorder->save($actor, $open, 'maintenance_order.alarm_again', $title);
            }

            return $this->create($actor, $a, ['kind' => 'breakdown', 'title' => $title, 'details' => $details, 'priority' => 'urgent', 'source' => $source, 'down' => true]);
        });
    }

    private function create(Actor $actor, MaintenanceAsset $a, array $d): MaintenanceOrder
    {
        $kind = isset(MaintenanceOrder::KINDS[$d['kind'] ?? '']) ? $d['kind'] : ($a->owner === 'customer' ? 'visit' : 'breakdown');
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            throw new DomainException('Say what the work is (e.g. “Fridge not cooling”).');
        }
        $assignee = ($d['assigned_to'] ?? null) ? User::find($d['assigned_to']) : null;
        $chargeable = $a->owner === 'customer' && ! $a->underWarranty() && ! $a->underContract();
        $o = (new MaintenanceOrder)->forceFill([
            'tenant_id' => $a->tenant_id, 'number' => $this->numbers->next($a->tenant_id, 'maintenance_order'), 'asset_id' => $a->id, 'plan_id' => $d['plan_id'] ?? null,
            'ticket_id' => $d['ticket_id'] ?? null, 'branch_id' => $a->branch_id, 'kind' => $kind, 'title' => mb_substr($title, 0, 255), 'details' => $d['details'] ?? null,
            'priority' => in_array($d['priority'] ?? '', ['urgent', 'high', 'normal', 'low'], true) ? $d['priority'] : 'normal', 'status' => 'open',
            'assigned_to' => $assignee?->id, 'due_on' => $d['due_on'] ?? null, 'checklist' => $d['checklist'] ?? null, 'chargeable' => $chargeable,
            'hourly_cost_minor' => (int) app(Settings::class)->get('maintenance.hourly_cost', $a->tenant_id) * 100, 'source' => $d['source'] ?? 'staff',
            'created_by_name' => $actor->name, 'down_from' => ($d['down'] ?? $kind === 'breakdown') ? now() : null,
        ]);
        $this->recorder->save($actor, $o, 'maintenance_order.opened', $title, $a->branch_id);
        if ($o->down_from && $a->status !== 'down') {
            $a->forceFill(['status' => 'down'])->save();
        }
        $title = ($o->priority === 'urgent' ? 'Urgent: ' : '')."{$o->number} {$a->name}: {$o->title}";
        if ($assignee) {
            Notification::make()->title($title)->body(MaintenanceOrder::KINDS[$kind])->sendToDatabase($assignee);
        } else {
            $this->notifier->toPermission($a->tenant_id, 'maintenance.work', $a->branch_id, $title, MaintenanceOrder::KINDS[$kind], url('/admin/maintenance-orders/'.$o->id), topic: 'service');
        }

        return $o;
    }

    /** From a ticket: the equipment it is about gets a work order (a breakdown, or a visit for a customer's). */
    public function fromTicket(Actor $actor, Ticket $t, ?string $assetId = null): MaintenanceOrder
    {
        $a = MaintenanceAsset::find($assetId ?? $t->asset_id) ?? throw new DomainException('Choose the equipment the ticket is about.');
        $order = $this->open($actor, $a, ['kind' => $a->owner === 'customer' ? 'visit' : 'breakdown', 'title' => $t->subject, 'details' => $t->description,
            'priority' => $t->priority, 'ticket_id' => $t->id, 'source' => 'ticket', 'assigned_to' => $t->assigned_to]);
        if (! $t->asset_id) {
            $t->forceFill(['asset_id' => $a->id])->save();
        }

        return $order;
    }

    public function start(Actor $actor, MaintenanceOrder $o): MaintenanceOrder
    {
        $this->requireOrder($actor, $o);
        $o->forceFill(['status' => 'working', 'started_at' => $o->started_at ?? now(), 'assigned_to' => $o->assigned_to ?? $actor->id]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $o, 'maintenance_order.started'));
    }

    public function waitForParts(Actor $actor, MaintenanceOrder $o, string $why): MaintenanceOrder
    {
        $this->requireOrder($actor, $o);
        $o->forceFill(['status' => 'waiting']);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $o, 'maintenance_order.waiting', $why));
    }

    /** Time a technician spent on the job, valued at their own cost rate when they have one, else the company's. */
    public function labour(Actor $actor, MaintenanceOrder $o, int $minutes, ?string $note = null): MaintenanceOrder
    {
        $this->requireOrder($actor, $o);
        if ($minutes <= 0 || $minutes > 24 * 60) {
            throw new DomainException('Enter the time spent (up to 24 hours at once).');
        }
        $rate = ProjectRate::where('user_id', $actor->id)->value('cost_rate_minor');
        $total = $o->labour_minutes + $minutes;
        $cost = $o->labourMinor() + (int) round($minutes * ($rate ?? $o->hourly_cost_minor) / 60);
        $o->forceFill(['labour_minutes' => $total, 'hourly_cost_minor' => $total ? (int) round($cost * 60 / $total) : $o->hourly_cost_minor,
            'status' => $o->status === 'open' ? 'working' : $o->status, 'started_at' => $o->started_at ?? now()]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $o, 'maintenance_order.labour', ($note ? "{$note}: " : '').$minutes.' min'));
    }

    /**
     * Parts out of a store for the job (or back with $return), at their cost.
     *
     * @param  list<array{product_uom_id: string, qty: string}>  $lines
     */
    public function parts(Actor $actor, MaintenanceOrder $o, Warehouse $warehouse, array $lines, bool $return = false): MaintenanceOrder
    {
        $this->requireOrder($actor, $o);
        if (! $actor->user || (! $this->authz->allows($actor->user, 'stock.adjust', $warehouse) && ! $this->authz->allows($actor->user, 'maintenance.work', $warehouse->branch))) {
            throw new AccessDenied;
        }
        $entries = [];
        foreach ($lines as $l) {
            $unit = ProductUom::with(['product', 'uom'])->find($l['product_uom_id'] ?? null) ?? throw new DomainException('Choose the part and its unit.');
            $milli = Qty::toMilli((string) ($l['qty'] ?? '0'));
            if ($milli <= 0 || ! $unit->product->track_stock) {
                throw new DomainException("Enter how many of {$unit->product->name} (a stock item).");
            }
            $entry = ['product' => $unit->product, 'unit' => $unit, 'qty_milli' => $return ? $milli : -$milli];
            if ($return) {
                $used = MaintenancePart::where('order_id', $o->id)->where('product_id', $unit->product_id);
                $qty = (float) $used->sum('qty');
                if ($qty * 1000 < $milli * (float) $unit->factor - 0.5) {
                    throw new DomainException("Only {$qty} of {$unit->product->name} were used on this job.");
                }
                $entry['unit_cost_minor'] = $qty > 0 ? (int) round((int) $used->sum('cost_minor') / $qty * (float) $unit->factor) : null;
            }
            $entries[] = $entry;
        }
        if ($entries === []) {
            throw new DomainException('Add at least one part.');
        }

        return $this->recorder->transaction(function () use ($actor, $o, $warehouse, $entries, $return) {
            $movements = $this->stock->post($actor, $warehouse, $return ? 'maintenance_return' : 'maintenance_issue', $entries,
                "{$o->number}: ".($return ? 'parts back' : 'parts used'), 'maintenance_order', $o->id);
            foreach ($movements as $mv) {
                $cost = -(int) round((float) $mv->qty_entered * (int) ($mv->unit_cost_minor ?? 0));
                MaintenancePart::create(['tenant_id' => $o->tenant_id, 'order_id' => $o->id, 'warehouse_id' => $warehouse->id, 'product_id' => $mv->product_id,
                    'qty' => -(float) $mv->qty_base, 'cost_minor' => $cost, 'stock_movement_id' => $mv->id, 'created_by_name' => $actor->name]);
                $o->parts_minor += $cost;
            }

            return $this->recorder->save($actor, $o, $return ? 'maintenance_order.parts_returned' : 'maintenance_order.parts_used', branchId: $o->branch_id);
        });
    }

    /** Outside technicians: supplier bill lines charged to work orders add to their cost when the bill is posted. */
    public function collectBill(SupplierBill $bill): void
    {
        foreach ($bill->lines()->whereNotNull('maintenance_order_id')->get() as $l) {
            if ($o = MaintenanceOrder::find($l->maintenance_order_id)) {
                $o->outside_minor += (int) $l->net_minor;
                $o->save();
            }
        }
    }

    /** The job is done: the asset works again, the plan moves on, the ticket hears of it. */
    public function complete(Actor $actor, MaintenanceOrder $o, array $d): MaintenanceOrder
    {
        $this->requireOrder($actor, $o);
        $outcome = trim((string) ($d['outcome'] ?? ''));
        if ($outcome === '') {
            throw new DomainException('Say what was done.');
        }
        $checklist = $d['checklist'] ?? $o->checklist;
        if ($checklist && collect($checklist)->contains(fn ($c) => ! ($c['done'] ?? false)) && ! ($d['skip_checklist'] ?? false)) {
            throw new DomainException('Tick every item of the checklist, or say why in what was done and leave them.');
        }
        $a = $o->asset;
        $meter = ($d['meter_reading'] ?? null) !== null && $d['meter_reading'] !== '' ? (float) $d['meter_reading'] : null;
        if ($meter !== null && $a->meter_reading !== null && $meter < (float) $a->meter_reading) {
            throw new DomainException('The meter reading is lower than the last one ('.$a->meter_reading.').');
        }

        return $this->recorder->transaction(function () use ($actor, $o, $a, $outcome, $checklist, $meter) {
            $o->forceFill(['status' => 'done', 'done_at' => now(), 'outcome' => mb_substr($outcome, 0, 500), 'checklist' => $checklist, 'meter_reading' => $meter,
                'down_until' => $o->down_from && ! $o->down_until ? now() : $o->down_until, 'started_at' => $o->started_at ?? now()]);
            $this->recorder->save($actor, $o, 'maintenance_order.done', $outcome, $o->branch_id);
            if ($meter !== null) {
                $a->meter_reading = $meter;
            }
            if ($a->status === 'down' && ! MaintenanceOrder::where('asset_id', $a->id)->whereKeyNot($o->id)->whereIn('status', ['open', 'working', 'waiting'])->whereNotNull('down_from')->exists()) {
                $a->status = 'working';
            }
            $a->save();
            if ($o->plan_id && ($p = MaintenancePlan::find($o->plan_id))) {
                $p->forceFill(['last_done_on' => today()->toDateString(), 'last_meter' => $meter ?? $a->meter_reading,
                    'next_due_on' => $p->every_days ? today()->addDays($p->every_days)->toDateString() : null])->save();
            }
            if ($o->ticket_id && ($t = Ticket::find($o->ticket_id)) && $t->isOpen()) {
                TicketMessage::create(['tenant_id' => $t->tenant_id, 'ticket_id' => $t->id, 'direction' => 'note', 'body' => "Work order {$o->number} done: {$outcome}",
                    'author_name' => $actor->name]);
            }

            return $o;
        });
    }

    public function cancel(Actor $actor, MaintenanceOrder $o, string $why): MaintenanceOrder
    {
        $this->requireOrder($actor, $o);
        if ($o->parts()->exists() && (float) $o->parts()->sum('qty') > 0) {
            throw new DomainException('Parts were used on this job: bring them back to stock first, or finish it.');
        }
        $o->forceFill(['status' => 'cancelled', 'outcome' => mb_substr(trim($why), 0, 500), 'down_until' => $o->down_from && ! $o->down_until ? now() : $o->down_until]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $o, 'maintenance_order.cancelled', $why));
    }

    /**
     * Invoice a chargeable customer job: parts at their price on the customer's list, labour at the amount given as the
     * chosen service.
     */
    public function invoice(Actor $actor, MaintenanceOrder $o, ?Product $labourService, int $labourMinor): Invoice
    {
        $this->require($actor, 'maintenance.manage', $o->asset->branch);
        if (! $o->chargeable || $o->asset->owner !== 'customer') {
            throw new DomainException('Only customers’ jobs out of warranty and contract are invoiced.');
        }
        if ($o->invoice_id) {
            throw new DomainException('This job is already invoiced.');
        }
        if ($o->status !== 'done') {
            throw new DomainException('Finish the job before invoicing it.');
        }
        $customer = Customer::findOrFail($o->asset->customer_id);
        $list = $customer->price_list_id ? PriceList::find($customer->price_list_id) : PriceList::where('is_default', true)->first();
        $lines = [];
        $add = function (Product $product, string $text, int $milli, int $amount, bool $inclusive) use (&$lines) {
            $unit = ProductUom::with('uom')->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
            $rates = $product->taxGroup?->rates->where('is_active', true)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() ?? [];
            $t = TaxCalculator::line($amount, $rates, $inclusive);
            $lines[] = ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => mb_substr($text, 0, 255), 'uom_code' => $unit->uom->code,
                'factor' => '1', 'qty' => Qty::format($milli), 'unit_price_minor' => (int) round($t['net'] * 1000 / max(1, $milli)),
                'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown']];
        };
        foreach ($o->parts()->get()->groupBy('product_id') as $productId => $used) {
            $milli = (int) round((float) $used->sum('qty') * 1000);
            if ($milli <= 0) {
                continue;
            }
            $product = Product::findOrFail($productId);
            $unit = ProductUom::where('product_id', $productId)->where('is_base', true)->firstOrFail();
            $price = $list ? app(PriceResolver::class)->resolve($list, $productId, $unit->id, $milli, now()) : null;
            if (! $price) {
                throw new DomainException("{$product->name} has no price on {$customer->name}’s price list.");
            }
            $add($product, $product->name, $milli, (int) round($price->amount_minor * $milli / 1000), (bool) $list->prices_include_tax);
        }
        if ($labourMinor > 0) {
            if (! $labourService || $labourService->track_stock) {
                throw new DomainException('Choose the service the labour shows as (not a stock item).');
            }
            $add($labourService, "{$labourService->name}: {$o->number} {$o->asset->name}", 1000, $labourMinor, false);
        }
        if ($lines === []) {
            throw new DomainException('Nothing to invoice: no parts and no labour.');
        }

        return $this->recorder->transaction(function () use ($actor, $o, $customer, $lines) {
            $invoice = app(InvoiceService::class)->raise($actor, $customer, $lines, ['branch_id' => $o->branch_id ?? $customer->home_branch_id ?? Branch::orderByDesc('is_hq')->value('id'),
                'currency_code' => Tenant::find($actor->tenantId)->currency_code, 'prices_include_tax' => false, 'customer_ref' => $o->number]);
            $o->forceFill(['invoice_id' => $invoice->id]);
            $this->recorder->save($actor, $o, 'maintenance_order.invoiced', $invoice->number, $o->branch_id);

            return $invoice;
        });
    }

    // --- helpers ---------------------------------------------------------------------------------------------------------

    private function requireOrder(Actor $actor, MaintenanceOrder $o): void
    {
        $this->require($actor, 'maintenance.work', $o->branch_id ? Branch::find($o->branch_id) : null);
        if (! $o->isOpen()) {
            throw new DomainException("Work order {$o->number} is ".strtolower(MaintenanceOrder::STATUSES[$o->status]).'.');
        }
    }

    private function require(Actor $actor, string $permission, ?Branch $branch = null): void
    {
        $this->desk->assertLicensed();
        if (! $actor->user || ! ($branch ? $this->authz->allows($actor->user, $permission, $branch) : $this->authz->allowsAnywhere($actor->user, $permission))) {
            throw new AccessDenied;
        }
    }
}
