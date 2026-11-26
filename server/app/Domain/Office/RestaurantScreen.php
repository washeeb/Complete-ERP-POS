<?php

namespace App\Domain\Office;

use App\Filament\Pages\RestaurantReport;
use App\Filament\Resources\DiningOrders\DiningOrderResource;
use App\Filament\Resources\DiningTables\DiningTableResource;
use App\Filament\Resources\KitchenStations\KitchenStationResource;
use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\DiningOrder;
use App\Models\DiningOrderItem;
use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Codes;
use App\Support\IndustryContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The restaurant in the new back office (part 30 of the rewrite), as the current pages: Bookings, Bills, Floor plan,
 * Kitchen stations, Modifiers and Tips & voids. Same queries (the resources' and page's, scoped to the person's branches)
 * and rules: the RestaurantSetup and ReservationService services (RestaurantController).
 */
final class RestaurantScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const BOOKING_TONE = ['booked' => 'informative', 'seated' => 'success', 'no_show' => 'danger', 'cancelled' => 'subtle'];

    public const BILL_STATUS = ['open' => 'Open', 'closed' => 'Paid', 'void' => 'Cancelled'];

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

    private static function qty(mixed $v): string
    {
        $v = (string) $v;

        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    private static function shown(Builder $q, array $p): void
    {
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('archived_at'), 'both' => null, default => $q->whereNull('archived_at')
        };
    }

    /** @return array<string, string> branches the person manages the restaurant of (or serves in) */
    private static function branches(string $permission): array
    {
        $ids = Erp::branchIds($permission);

        return Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->pluck('name', 'id')->all();
    }

    public static function canBookings(): bool
    {
        return ReservationResource::canViewAny();
    }

    public static function canBills(): bool
    {
        return DiningOrderResource::canViewAny();
    }

    public static function canSetup(): bool
    {
        return DiningTableResource::canViewAny();
    }

    public static function canModifiers(): bool
    {
        return ModifierGroupResource::canViewAny();
    }

    public static function canReport(): bool
    {
        return RestaurantReport::canAccess();
    }

    // -------------------------------------------------------------------------------------------- Bookings

    public function bookingsQuery(): Builder
    {
        return ReservationResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, upcoming (1 by default: from today), status, page, per */
    public function bookings(array $p): array
    {
        $q = $this->bookingsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('guest_name', 'ilike', "%{$s}%")->orWhere('number', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%"));
        }
        $upcoming = ($p['upcoming'] ?? '1') !== '0';
        if ($upcoming) {
            $q->where('starts_at', '>=', now()->startOfDay());
        }
        if (isset(Reservation::STATUSES[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $serve = Erp::canAnywhere('restaurant.serve');

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'upcoming' => $upcoming, 'statuses' => self::choices(Reservation::STATUSES),
            'can_create' => ReservationResource::canCreate(),
            'rows' => $q->orderBy('starts_at')->forPage($page, $per)->get()->map(function (Reservation $r) use ($serve) {
                $branch = Branch::find($r->branch_id);

                return ['id' => $r->id, 'number' => $r->number, 'when' => $r->starts_at?->toIso8601String(), 'name' => $r->guest_name, 'phone' => $r->phone,
                    'guests' => (int) $r->party_size, 'table' => $r->table?->code, 'branch' => $r->branch?->name, 'status' => $r->status,
                    'status_label' => Reservation::STATUSES[$r->status] ?? $r->status, 'tone' => self::BOOKING_TONE[$r->status] ?? 'subtle',
                    'deposit' => $r->deposit_minor ? (int) $r->deposit_minor : null, 'deposit_status' => $r->deposit_status, 'note' => $r->note,
                    'can_change' => $serve && ReservationResource::canEdit($r), 'can_no_show' => $r->status === 'booked' && $r->starts_at->lt(now()) && Erp::can('restaurant.serve', $branch),
                    'can_cancel' => $r->status === 'booked' && Erp::can('restaurant.serve', $branch),
                    'can_forfeit' => in_array($r->status, ['no_show', 'cancelled'], true) && $r->depositLeft() > 0 && Erp::can('restaurant.manage', $branch),
                    'left' => $r->depositLeft(),
                    'values' => ['branch_id' => $r->branch_id, 'guest_name' => $r->guest_name, 'phone' => (string) $r->phone, 'party_size' => (string) $r->party_size,
                        'starts_at' => $r->starts_at?->format('Y-m-d\TH:i'), 'duration_minutes' => (string) ($r->duration_minutes ?? 90),
                        'dining_table_id' => (string) $r->dining_table_id, 'note' => (string) $r->note]];
            })->all()];
    }

    /** The booking form's branches and, for the chosen branch, its tables. */
    public function bookingForm(?string $branch): array
    {
        $branches = self::branches('restaurant.serve');
        $branch = isset($branches[$branch ?? '']) ? $branch : array_key_first($branches);

        return ['branches' => self::choices($branches), 'branch' => (string) $branch,
            'tables' => DiningTable::where('branch_id', $branch)->where('is_active', true)->whereNull('archived_at')->orderBy('code')->get()
                ->map(fn ($t) => ['id' => $t->id, 'label' => "{$t->code} ({$t->seats} seats)"])->all(),
            'starts_at' => now()->addHour()->startOfHour()->format('Y-m-d\TH:i')];
    }

    // -------------------------------------------------------------------------------------------- Bills

    public function billsQuery(): Builder
    {
        return DiningOrderResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status, kind, page, per */
    public function bills(array $p): array
    {
        $q = $this->billsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('waiter_name', 'ilike', "%{$s}%"));
        }
        if (isset(self::BILL_STATUS[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        if (isset(DiningOrder::KINDS[$p['kind'] ?? ''])) {
            $q->where('kind', $p['kind']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(self::BILL_STATUS), 'kinds' => self::choices(DiningOrder::KINDS),
            'rows' => $q->orderByDesc('opened_at')->forPage($page, $per)->get()->map(fn (DiningOrder $o) => ['id' => $o->id, 'number' => $o->number,
                'opened' => $o->opened_at?->toIso8601String(), 'where' => $o->label(), 'waiter' => $o->waiter_name, 'status' => $o->status,
                'status_label' => self::BILL_STATUS[$o->status] ?? $o->status, 'tone' => ['open' => 'warning', 'closed' => 'success'][$o->status] ?? 'subtle',
                'voids' => $o->items->where('status', 'void')->count()])->all()];
    }

    public function bill(DiningOrder $o): array
    {
        return ['id' => $o->id, 'number' => $o->number, 'where' => $o->label(), 'waiter' => $o->waiter_name, 'status_label' => self::BILL_STATUS[$o->status] ?? $o->status,
            'opened' => $o->opened_at?->toIso8601String(), 'closed' => $o->closed_at?->toIso8601String(), 'guests' => $o->covers, 'note' => $o->note,
            'items' => $o->items->map(fn (DiningOrderItem $i) => ['id' => $i->id, 'qty' => self::qty($i->qty), 'name' => $i->name,
                'extras' => collect($i->modifiers ?? [])->pluck('name')->push($i->note)->filter()->implode(', '), 'status' => $i->status,
                'status_label' => DiningOrderItem::STATUSES[$i->status] ?? $i->status, 'void' => $i->status === 'void' ? trim("{$i->void_reason} ({$i->voided_by_name})") : null,
                'paid' => (bool) $i->sale_id])->values()->all()];
    }

    // -------------------------------------------------------------------------------------------- Floor plan, kitchen stations

    /** @param  array<string, mixed>  $p  q, branch, show */
    public function tables(array $p): array
    {
        $q = DiningTableResource::getEloquentQuery()->with('branch');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('code', 'ilike', "%{$s}%");
        }
        if (($p['branch'] ?? '') !== '') {
            $q->where('branch_id', $p['branch']);
        }
        self::shown($q, $p);
        $archive = new CompanyScreen($this->user);
        $branches = self::branches('restaurant.manage');

        return ['branches' => self::choices($branches), 'can_create' => DiningTableResource::canCreate(),
            'next' => collect($branches)->keys()->mapWithKeys(fn ($b) => [$b => Codes::after(DiningTable::where('branch_id', $b)->pluck('code')) ?? '1'])->all(),
            'rows' => $q->orderBy('branch_id')->orderByRaw('length(code), code')->get()->map(fn (DiningTable $t) => ['id' => $t->id, 'branch' => $t->branch?->code,
                'code' => $t->code, 'name' => $t->code, 'area' => $t->area, 'seats' => (int) $t->seats, 'position' => "column {$t->pos_x}, row {$t->pos_y}",
                'can_edit' => DiningTableResource::canEdit($t),
                'values' => ['branch_id' => $t->branch_id, 'code' => $t->code, 'area' => (string) $t->area, 'seats' => (string) $t->seats,
                    'pos_x' => (string) $t->pos_x, 'pos_y' => (string) $t->pos_y]] + $archive->archiveOf($t))->all()];
    }

    /** @param  array<string, mixed>  $p  show */
    public function stations(array $p): array
    {
        $q = KitchenStationResource::getEloquentQuery()->with('branch');
        self::shown($q, $p);
        $archive = new CompanyScreen($this->user);

        return ['branches' => self::choices(self::branches('restaurant.manage')), 'can_create' => KitchenStationResource::canCreate(),
            'rows' => $q->orderBy('code')->get()->map(fn (KitchenStation $s) => ['id' => $s->id, 'branch' => $s->branch?->code, 'code' => $s->code, 'name' => $s->name,
                'auto_print' => (bool) $s->auto_print, 'can_edit' => KitchenStationResource::canEdit($s),
                'values' => ['branch_id' => $s->branch_id, 'name' => $s->name, 'auto_print' => (bool) $s->auto_print]] + $archive->archiveOf($s))->all()];
    }

    // -------------------------------------------------------------------------------------------- Modifiers

    /** @param  array<string, mixed>  $p  q, show */
    public function modifiers(array $p): array
    {
        $q = ModifierGroupResource::getEloquentQuery()->with('options');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('name', 'ilike', "%{$s}%");
        }
        self::shown($q, $p);
        $archive = new CompanyScreen($this->user);
        $groups = $q->orderBy('name')->get();
        $products = Product::whereIn('id', $groups->flatMap(fn ($g) => $g->options->pluck('product_id'))->filter()->unique())->get()
            ->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"]);
        $industries = IndustryContext::choices($this->user);
        $many = count(array_diff_key($industries, [IndustryContext::SHARED => true])) > 1 || isset($industries[IndustryContext::SHARED]);

        return ['can_create' => ModifierGroupResource::canCreate(), 'industries' => $many ? self::choices($industries) : null,
            'default_industry' => (string) IndustryContext::defaultId(),
            'rows' => $groups->map(fn (ModifierGroup $g) => ['id' => $g->id, 'name' => $g->name,
                'rule' => $g->min_choices === $g->max_choices ? (string) $g->min_choices : "{$g->min_choices}–{$g->max_choices}",
                'choices' => $g->options->pluck('name')->implode(', '), 'active' => (bool) $g->is_active,
                'values' => ['name' => $g->name, 'industry_id' => (string) ($g->industry_id ?? IndustryContext::SHARED), 'min_choices' => (string) $g->min_choices,
                    'max_choices' => (string) $g->max_choices, 'is_active' => (bool) $g->is_active,
                    'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'product_id' => (string) $o->product_id,
                        'product' => $products[$o->product_id] ?? null])->values()->all()]] + $archive->archiveOf($g))->all()];
    }

    /** Products a choice can be charged as. */
    public function products(string $q): array
    {
        return Product::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(30)->get()->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->sku} · {$p->name}"])->all();
    }

    // -------------------------------------------------------------------------------------------- Tips & voids

    public function report(?string $from, ?string $to): array
    {
        $ok = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $ok($from) ? $from : now()->startOfMonth()->toDateString();
        $to = $ok($to) ? $to : now()->toDateString();
        // The current page's own queries (its period and branches), so both show the same figures.
        $page = new RestaurantReport;
        $page->from = $from;
        $page->to = $to;
        $tips = $page->tips();
        $voids = $page->voids();

        return $this->m() + ['from' => $from, 'to' => $to,
            'tips' => $tips->map(fn ($t) => ['name' => $t->name ?? '—', 'bills' => (int) $t->bills, 'tips' => (int) $t->tips])->all(),
            'voids' => $voids->map(fn ($v, $i) => ['id' => (string) $i, 'bill' => $v->number, 'added' => $v->added_at ? CarbonImmutable::parse($v->added_at)->toIso8601String() : null,
                'item' => self::qty($v->qty).' × '.$v->name, 'fired' => (bool) $v->fired_at, 'reason' => $v->void_reason, 'by' => $v->voided_by_name,
                'added_by' => $v->added_by_name])->all()];
    }
}
