<?php

namespace App\Domain\Office;

use App\Filament\Resources\MaintenanceAssets\MaintenanceAssetResource;
use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Support\Fields;
use App\Filament\Support\ServiceUi;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePlan;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Maintenance in the new back office (part 31 of the rewrite), as the current pages: Equipment (list, one piece of
 * equipment with its servicing plans and work orders) and Work orders (list, new, one work order). Same queries (the
 * resources', scoped to the person's branches) and rules: the Maintenance service (MaintenanceController).
 */
final class MaintenanceScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const PRIORITIES = ['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];

    public const ASSET_TONE = ['working' => 'success', 'down' => 'danger', 'retired' => 'subtle'];

    public const ORDER_TONE = ['done' => 'success', 'working' => 'informative', 'waiting' => 'warning', 'open' => 'subtle', 'cancelled' => 'subtle'];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function m(): array
    {
        $c = $this->user->tenant?->currency_code ?? 'GHS';

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    private static function num(mixed $v): string
    {
        $v = (string) ($v ?? '');

        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    public static function span(int $minutes): string
    {
        return $minutes >= 1440 ? round($minutes / 1440, 1).' day(s)' : intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }

    public static function canView(): bool
    {
        return MaintenanceAssetResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return ServiceUi::can('maintenance.manage');
    }

    public static function canWork(): bool
    {
        return ServiceUi::can('maintenance.work');
    }

    // -------------------------------------------------------------------------------------------- Equipment

    public function assetsQuery(): Builder
    {
        return MaintenanceAssetResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, owner, status, category, show, page, per */
    public function equipment(array $p): array
    {
        $q = $this->assetsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('maintenance_assets.number', 'ilike', "%{$s}%")->orWhere('maintenance_assets.name', 'ilike', "%{$s}%")
                ->orWhere('serial_no', 'ilike', "%{$s}%")->orWhere('make', 'ilike', "%{$s}%")->orWhere('model', 'ilike', "%{$s}%"));
        }
        if (in_array($p['owner'] ?? '', ['own', 'customer'], true)) {
            $q->where('owner', $p['owner']);
        }
        foreach (['status' => MaintenanceAsset::STATUSES, 'category' => MaintenanceAsset::CATEGORIES] as $k => $map) {
            if (isset($map[$p[$k] ?? ''])) {
                $q->where("maintenance_assets.{$k}", $p[$k]);
            }
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('maintenance_assets.archived_at'), 'both' => null, default => $q->whereNull('maintenance_assets.archived_at')
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $archive = new CompanyScreen($this->user);

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(MaintenanceAsset::STATUSES), 'categories' => self::choices(MaintenanceAsset::CATEGORIES),
            'can_create' => MaintenanceAssetResource::canCreate(),
            'rows' => $q->orderBy('maintenance_assets.number')->forPage($page, $per)->get()->map(fn (MaintenanceAsset $a) => ['id' => $a->id, 'number' => $a->number, 'name' => $a->name,
                'where' => $a->owner === 'customer' ? 'Customer: '.$a->customer?->name : trim(($a->branch?->name ?? '').' · '.($a->location ?? ''), ' ·'),
                'category' => MaintenanceAsset::CATEGORIES[$a->category] ?? $a->category, 'status' => $a->status, 'status_label' => MaintenanceAsset::STATUSES[$a->status] ?? $a->status,
                'tone' => self::ASSET_TONE[$a->status] ?? 'subtle', 'warranty' => $a->warranty_until?->toDateString(), 'warranty_over' => $a->warranty_until && ! $a->underWarranty(),
                'meter' => $a->meter_reading === null ? null : self::num($a->meter_reading).' '.($a->meter_unit === 'km' ? 'km' : 'h'),
                'next' => $a->plans()->where('is_active', true)->whereNotNull('next_due_on')->min('next_due_on')] + $archive->archiveOf($a))->all()];
    }

    public function asset(?MaintenanceAsset $a): array
    {
        $manage = self::canManage();
        $work = self::canWork();
        $base = $this->m() + ['id' => $a?->id, 'number' => $a?->number, 'status_label' => $a ? MaintenanceAsset::STATUSES[$a->status] ?? $a->status : null,
            'tone' => $a ? self::ASSET_TONE[$a->status] ?? 'subtle' : null, 'statuses' => self::choices(MaintenanceAsset::STATUSES),
            'categories' => self::choices(MaintenanceAsset::CATEGORIES), 'branches' => self::choices(Branch::where('is_active', true)->orderBy('code')->pluck('name', 'id')->all()),
            'suppliers' => self::choices(Supplier::orderBy('name')->pluck('name', 'id')->all()),
            'fixed_assets' => self::choices(FixedAsset::where('status', 'active')->orderBy('number')->get()->mapWithKeys(fn ($f) => [$f->id => "{$f->number} {$f->name}"])->all()),
            'values' => ['name' => (string) $a?->name, 'status' => $a?->status ?? 'working', 'owner' => $a?->owner ?? 'own', 'category' => $a?->category ?? 'other',
                'branch_id' => (string) $a?->branch_id, 'location' => (string) $a?->location, 'customer_id' => (string) $a?->customer_id, 'customer_name' => $a?->customer?->name,
                'make' => (string) $a?->make, 'model' => (string) $a?->model, 'serial_no' => (string) $a?->serial_no, 'supplier_id' => (string) $a?->supplier_id,
                'acquired_on' => (string) $a?->acquired_on?->toDateString(), 'warranty_until' => (string) $a?->warranty_until?->toDateString(),
                'contract_until' => (string) $a?->contract_until?->toDateString(), 'fixed_asset_id' => (string) $a?->fixed_asset_id, 'meter_unit' => (string) $a?->meter_unit,
                'notes' => (string) $a?->notes],
            'actions' => ['edit' => $a ? $manage : MaintenanceAssetResource::canCreate()]];
        if (! $a) {
            return $base;
        }

        return array_replace($base, ['archive' => (new CompanyScreen($this->user))->archiveOf($a), 'meter' => $a->meter_reading === null ? null : self::num($a->meter_reading).' '.($a->meter_unit === 'km' ? 'km' : 'h'),
            'meter_unit' => $a->meter_unit, 'people' => self::choices(ServiceUi::people('maintenance.work')),
            'actions' => $base['actions'] + ['breakdown' => $a->status !== 'retired' && $work, 'meter' => (bool) $a->meter_unit && $work, 'plans' => $manage],
            'plans' => $a->plans()->with('assignee')->orderBy('name')->get()->map(fn (MaintenancePlan $p) => ['id' => $p->id, 'name' => $p->name, 'every' => $p->describe(),
                'last' => $p->last_done_on?->toDateString(), 'next' => $p->next_due_on?->toDateString(), 'late' => (bool) $p->next_due_on?->isPast(), 'who' => $p->assignee?->name,
                'active' => (bool) $p->is_active,
                'values' => ['name' => $p->name, 'every_days' => (string) ($p->every_days ?? ''), 'every_meter' => self::num($p->every_meter), 'last_done_on' => (string) $p->last_done_on?->toDateString(),
                    'lead_days' => (string) ($p->lead_days ?? 7), 'assigned_to' => (string) $p->assigned_to, 'is_active' => (bool) $p->is_active, 'checklist' => array_values($p->checklist ?? [])]])->all(),
            'orders' => $a->orders()->orderByDesc('created_at')->limit(200)->get()->map(fn (MaintenanceOrder $o) => ['id' => $o->id, 'number' => $o->number, 'title' => $o->title,
                'kind' => MaintenanceOrder::KINDS[$o->kind] ?? $o->kind, 'status_label' => MaintenanceOrder::STATUSES[$o->status] ?? $o->status, 'tone' => self::ORDER_TONE[$o->status] ?? 'subtle',
                'cost' => $o->totalMinor(), 'down' => $o->down_from ? self::span($o->downtimeMinutes()) : null, 'done' => $o->done_at?->toDateString()])->all()]);
    }

    // -------------------------------------------------------------------------------------------- Work orders

    public function ordersQuery(): Builder
    {
        return MaintenanceOrderResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, open (1 by default, 0 closed, all), mine, kind, page, per */
    public function orders(array $p): array
    {
        $q = $this->ordersQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('maintenance_orders.number', 'ilike', "%{$s}%")->orWhere('maintenance_orders.title', 'ilike', "%{$s}%"));
        }
        $open = (string) ($p['open'] ?? '1');
        match ($open) {
            '1' => $q->whereIn('maintenance_orders.status', ['open', 'working', 'waiting']),
            '0' => $q->whereIn('maintenance_orders.status', ['done', 'cancelled']),
            default => $open = 'all',
        };
        if (($p['mine'] ?? '') === '1') {
            $q->where('maintenance_orders.assigned_to', $this->user->id);
        }
        if (isset(MaintenanceOrder::KINDS[$p['kind'] ?? ''])) {
            $q->where('maintenance_orders.kind', $p['kind']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'open' => $open, 'kinds' => self::choices(MaintenanceOrder::KINDS),
            'can_create' => MaintenanceOrderResource::canCreate(), 'work' => self::canWork(),
            'rows' => $q->orderByDesc('maintenance_orders.created_at')->forPage($page, $per)->get()->map(fn (MaintenanceOrder $o) => ['id' => $o->id, 'number' => $o->number,
                'title' => $o->title, 'asset' => $o->asset?->name, 'kind' => MaintenanceOrder::KINDS[$o->kind] ?? $o->kind, 'priority' => self::PRIORITIES[$o->priority] ?? ucfirst((string) $o->priority),
                'priority_tone' => ['urgent' => 'danger', 'high' => 'warning'][$o->priority] ?? 'subtle', 'status_label' => MaintenanceOrder::STATUSES[$o->status] ?? $o->status,
                'tone' => self::ORDER_TONE[$o->status] ?? 'subtle', 'due' => $o->due_on?->toDateString(), 'late' => $o->isOpen() && (bool) $o->due_on?->isPast(),
                'who' => $o->assignee?->name, 'cost' => $o->totalMinor()])->all()];
    }

    /** What "New work order" offers (the equipment chosen when it comes from an equipment page). */
    public function orderForm(?string $asset): array
    {
        $assets = ServiceUi::maintenance(MaintenanceAsset::query())->whereNull('archived_at')->where('status', '<>', 'retired')->orderBy('number')->get()
            ->mapWithKeys(fn ($a) => [$a->id => "{$a->number} {$a->name}"])->all();

        return ['assets' => self::choices($assets), 'kinds' => self::choices(MaintenanceOrder::KINDS), 'priorities' => self::choices(self::PRIORITIES),
            'people' => self::choices(ServiceUi::people('maintenance.work')),
            'values' => ['asset_id' => isset($assets[$asset ?? '']) ? $asset : '', 'kind' => 'breakdown', 'priority' => 'normal', 'title' => '', 'due_on' => '', 'assigned_to' => '',
                'down' => false, 'details' => '']];
    }

    public function order(MaintenanceOrder $o): array
    {
        $work = self::canWork();
        $open = $o->isOpen() && $work;
        $a = $o->asset;

        return $this->m() + ['id' => $o->id, 'number' => $o->number, 'title' => $o->title, 'status' => $o->status, 'status_label' => MaintenanceOrder::STATUSES[$o->status] ?? $o->status,
            'tone' => self::ORDER_TONE[$o->status] ?? 'subtle', 'asset' => $a ? ['id' => $a->id, 'name' => $a->name,
                'where' => $a->owner === 'customer' ? 'Customer: '.$a->customer?->name : $a->location, 'open' => "/office/maintenance/equipment/{$a->id}"] : null,
            'kind' => MaintenanceOrder::KINDS[$o->kind] ?? $o->kind, 'who' => $o->assignee?->name, 'due' => $o->due_on?->toDateString(),
            'down' => $o->down_from ? self::span($o->downtimeMinutes()).($o->down_until ? '' : ' so far') : 'Still working',
            'paid_by' => $a?->owner !== 'customer' ? 'Us' : ($o->chargeable ? 'The customer' : 'Us (warranty or contract)'),
            'invoice' => $o->invoice_id ? ['number' => $o->invoice?->number, 'open' => "/office/invoices/{$o->invoice_id}"] : null,
            'details' => $o->details, 'outcome' => $o->outcome,
            'labour' => intdiv((int) $o->labour_minutes, 60).' h '.((int) $o->labour_minutes % 60).' min', 'labour_minor' => $o->labourMinor(), 'parts_minor' => (int) $o->parts_minor,
            'outside_minor' => (int) $o->outside_minor, 'total' => $o->totalMinor(),
            'checklist' => collect($o->checklist ?? [])->map(fn ($c) => ['item' => (string) ($c['item'] ?? ''), 'done' => (bool) ($c['done'] ?? false)])->values()->all(),
            'parts' => $o->parts()->with('product')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->product?->name, 'qty' => self::num($p->qty), 'cost' => (int) $p->cost_minor])->all(),
            'meter_unit' => $a?->meter_unit, 'warehouses' => $open ? self::choices(Fields::warehouses(['stock.adjust', 'maintenance.work'])) : [],
            'actions' => ['start' => $o->status === 'open' && $work, 'labour' => $open, 'parts' => $open, 'parts_back' => $open && $o->parts()->exists(),
                'wait' => $o->status !== 'waiting' && $open, 'complete' => $open, 'cancel' => $open,
                'invoice' => $o->status === 'done' && $o->chargeable && ! $o->invoice_id && self::canManage()]];
    }
}
