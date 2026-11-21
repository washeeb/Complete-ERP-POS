<?php

namespace App\Domain\Office;

use App\Domain\Pharmacy\InteractionStarter;
use App\Domain\Pharmacy\PharmacyReports;
use App\Domain\Pharmacy\PharmacyService;
use App\Filament\Pages\ControlledDrugRegister;
use App\Filament\Resources\DrugInteractions\DrugInteractionResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\RefillReminders\RefillReminderResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\DrugInteraction;
use App\Models\Prescription;
use App\Models\RefillReminder;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\People;
use Illuminate\Support\Carbon;

/**
 * Pharmacy in the new back office (part 11 of the rewrite), as the current screens: prescriptions dispensed at the
 * tills (read only, with the medicines and the warnings the pharmacist saw), refill reminders (Send now, Cancel),
 * the drug-interaction table (add, change, the starter list) and the controlled-drug register (read from the stock
 * ledger, with Print and the NHIS claims CSV). Every rule stays in PharmacyService, RefillReminders and
 * PharmacyReports; who may see and do what is the current screens' (PharmacyController).
 */
final class PharmacyScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    private static function paging(array $p, int $default = 25): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : $default, max(1, (int) ($p['page'] ?? 1))];
    }

    private static function pairs(array $a): array
    {
        return array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);
    }

    /** The name a person had then, and the name their account has now when it was renamed. */
    private static function person(?string $id, ?string $then): ?string
    {
        if ($then === null) {
            return null;
        }
        $now = People::renamedTo($id, $then);

        return $now ? "{$then} (now {$now})" : $then;
    }

    /** @param  array<string, mixed>  $p  q, offline (yes | no), dir, page, per */
    public function prescriptions(array $p): array
    {
        [$per, $page] = self::paging($p);
        $q = PrescriptionResource::getEloquentQuery();
        if (($search = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$search}%")->orWhere('reference', 'ilike', "%{$search}%")
                ->orWhere('prescriber_name', 'ilike', "%{$search}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%")));
        }
        match ($p['offline'] ?? '') {
            'yes' => $q->where('approved_offline', true), 'no' => $q->where('approved_offline', false), default => null,
        };
        $dir = ($p['dir'] ?? '') === 'asc' ? 'asc' : 'desc';

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'dir' => $dir,
            'rows' => $q->orderBy('created_at', $dir)->orderBy('id', $dir)->forPage($page, $per)->get()->map(fn (Prescription $r) => [
                'id' => $r->id, 'number' => $r->number, 'dispensed_at' => $r->created_at?->toIso8601String(), 'patient' => $r->customer?->name,
                'prescriber' => $r->prescriber_name, 'pharmacist' => self::person($r->pharmacist_id, $r->pharmacist_name),
                'warnings' => count($r->warnings ?? []), 'offline' => (bool) $r->approved_offline,
            ])->all()];
    }

    public function prescription(Prescription $r): array
    {
        $lines = $r->sale?->lines()->where('prescription_id', $r->id)->get() ?? collect();

        return ['id' => $r->id, 'number' => $r->number, 'dispensed_at' => ($r->sale?->occurred_at ?? $r->created_at)?->toIso8601String(),
            'patient' => $r->customer?->name, 'receipt' => $r->sale?->receipt_no, 'sale_id' => $r->sale_id && SaleResource::canViewAny() ? $r->sale_id : null,
            'prescriber' => $r->prescriber_name, 'prescriber_reg_no' => $r->prescriber_reg_no, 'facility' => $r->facility, 'reference' => $r->reference,
            'issued_on' => $r->issued_on?->toDateString(), 'pharmacist' => self::person($r->pharmacist_id, $r->pharmacist_name), 'offline' => (bool) $r->approved_offline,
            'medicines' => $lines->map(fn ($l) => Fields::qty((string) $l->qty).' '.$l->uom_code.' × '.$l->name)->values()->all(),
            'warnings' => collect($r->warnings ?? [])->map(fn ($w) => ['severity' => ucfirst((string) ($w['severity'] ?? '')), 'message' => (string) ($w['message'] ?? '')])->all()];
    }

    /** @param  array<string, mixed>  $p  q, status (default waiting; "all" = every status), dir, page, per */
    public function reminders(array $p): array
    {
        [$per, $page] = self::paging($p);
        $q = RefillReminderResource::getEloquentQuery();
        if (($search = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('product_name', 'ilike', "%{$search}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%")));
        }
        $status = (string) ($p['status'] ?? '');
        if ($status !== 'all') {
            $q->where('status', array_key_exists($status, RefillReminder::STATUSES) ? $status : 'waiting');
        }
        $dir = ($p['dir'] ?? '') === 'desc' ? 'desc' : 'asc';

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'dir' => $dir, 'statuses' => self::pairs(RefillReminder::STATUSES),
            'rows' => $q->orderBy('remind_on', $dir)->orderBy('id')->forPage($page, $per)->get()->map(function (RefillReminder $r) {
                $may = $r->status === 'waiting' && Erp::can('customers.manage', $r->branch);

                return ['id' => $r->id, 'status' => $r->status, 'status_label' => RefillReminder::STATUSES[$r->status] ?? $r->status,
                    'customer' => $r->customer?->name, 'phone' => $r->customer?->phone, 'medicine' => $r->product_name, 'sold' => Fields::qty((string) $r->qty_base),
                    'runs_out_on' => $r->runs_out_on?->toDateString(), 'remind_on' => $r->remind_on?->toDateString(), 'branch' => $r->branch?->name,
                    'sent_at' => $r->sent_at?->toIso8601String(), 'channel' => $r->channel ? ($r->channel === 'whatsapp' ? 'WhatsApp' : strtoupper($r->channel)) : null,
                    'cancelled_by' => $r->closed_by_name, 'can_act' => $may];
            })->all()];
    }

    /** @param  array<string, mixed>  $p  q, severity, page, per */
    public function interactions(array $p): array
    {
        [$per, $page] = self::paging($p, 50);
        $q = DrugInteraction::query();
        if (($search = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('ingredient_a', 'ilike', "%{$search}%")->orWhere('ingredient_b', 'ilike', "%{$search}%"));
        }
        if (array_key_exists((string) ($p['severity'] ?? ''), DrugInteraction::SEVERITIES)) {
            $q->where('severity', $p['severity']);
        }

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'can_manage' => DrugInteractionResource::canCreate(),
            'severities' => self::pairs(DrugInteraction::SEVERITIES), 'starter' => count(InteractionStarter::ROWS), 'unchecked' => PharmacyService::unchecked(),
            'rows' => $q->orderBy('ingredient_a')->orderBy('ingredient_b')->forPage($page, $per)->get()->map(fn (DrugInteraction $r) => [
                'id' => $r->id, 'ingredient_a' => $r->ingredient_a, 'ingredient_b' => $r->ingredient_b, 'severity' => $r->severity,
                'severity_label' => DrugInteraction::SEVERITIES[$r->severity] ?? $r->severity, 'note' => $r->note,
            ])->all()];
    }

    /** Warehouses in the branches where the person dispenses or manages the pharmacy (as the current page). */
    public function warehouses(): array
    {
        $ids = PrescriptionResource::branchIds();

        return Warehouse::when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()
            ->map(fn ($w) => ['id' => $w->id, 'label' => "{$w->code} · {$w->name}"])->all();
    }

    /** @param  array<string, mixed>  $p  warehouse, from, to (default: the first warehouse, this month) */
    public function register(array $p): array
    {
        $warehouses = $this->warehouses();
        $warehouse = (string) ($p['warehouse'] ?? '');
        if (! in_array($warehouse, array_column($warehouses, 'id'), true)) {
            $warehouse = $warehouses[0]['id'] ?? '';
        }
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $from = $date($p['from'] ?? null) ?? now()->startOfMonth()->toDateString();
        $to = $date($p['to'] ?? null) ?? now()->toDateString();
        $products = $warehouse !== '' && $from <= $to ? PharmacyReports::controlledRegister($warehouse, $from, $to) : [];

        return ['warehouses' => $warehouses, 'warehouse' => $warehouse, 'from' => $from, 'to' => $to, 'bad_period' => $from > $to,
            'can_claims' => ControlledDrugRegister::canAccess() && Erp::canAnywhere('pharmacy.manage'),
            'print' => $warehouse !== '' ? route('print.controlled-register', ['warehouse' => $warehouse, 'from' => $from, 'to' => $to], false) : null,
            'claims' => route('print.nhis-claims', ['from' => $from, 'to' => $to], false),
            'products' => array_map(fn ($x) => ['id' => $x['product']->id, 'name' => $x['product']->name.($x['product']->strength ? ' '.$x['product']->strength : ''),
                'opening' => Fields::qty($x['opening']), 'closing' => Fields::qty($x['closing']),
                'rows' => array_map(fn ($r) => ['at' => Carbon::parse($r->occurred_at)->toIso8601String(), 'entry' => str_replace('_', ' ', (string) $r->type),
                    'ref' => trim(($r->receipt_no ?? '').' '.($r->rx_number ?? '')) ?: null, 'batch' => $r->batch_no, 'patient' => $r->patient,
                    'prescriber' => $r->prescriber_name, 'by' => $r->pharmacist_name ?? $r->actor_name,
                    'qty' => Fields::qty((string) $r->qty_base), 'balance' => Fields::qty((string) $r->balance)], $x['rows']),
            ], $products)];
    }
}
