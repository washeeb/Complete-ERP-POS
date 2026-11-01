<?php

namespace App\Domain\Purchasing;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseAgreementLine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contracts and blanket orders (procurement stage 4). A contract fixes a supplier's prices for a period; a blanket
 * order also says how much may be ordered (per item and / or in value) to be drawn down by orders over time. Made as
 * a draft, put in force by someone else with "Approve purchase orders", closed when done. While in force, an order to
 * that supplier takes the agreed price when no price is typed, never pays more than it, and counts against its caps.
 * The buyers are told before it ends and when it has ended.
 */
final class AgreementService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly Notifier $notifier,
        private readonly Settings $settings,
    ) {}

    /**
     * @param array{supplier_id: string, kind: string, title: string, starts_on: string, ends_on: string, value_cap?: ?string, terms?: ?string,
     *   file_path?: ?string, file_name?: ?string, lines: list<array{product_uom_id?: ?string, account_id?: ?string, description?: ?string,
     *   uom_code?: ?string, unit_cost: string, max_qty?: ?string, note?: ?string}>} $data
     */
    public function save(Actor $actor, PurchaseAgreement $a, array $data): PurchaseAgreement
    {
        $this->require($actor, 'purchasing.manage');
        if ($a->exists && $a->status !== 'draft') {
            throw new DomainException("{$a->number} is in force; close it and make a new one to change it.");
        }
        $supplier = Supplier::findOrFail($data['supplier_id'] ?? $a->supplier_id);
        if (! array_key_exists($data['kind'] ?? '', PurchaseAgreement::KINDS)) {
            throw new DomainException('Choose contract or blanket order.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new DomainException('Give the agreement a title (e.g. "Paracetamol prices 2027").');
        }
        $starts = Carbon::parse($data['starts_on'] ?? 'today');
        $ends = Carbon::parse($data['ends_on'] ?? 'today');
        if ($ends->lt($starts)) {
            throw new DomainException('The end date is before the start date.');
        }
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $cap = ($data['value_cap'] ?? null) !== null && $data['value_cap'] !== '' ? Money::toMinor((string) $data['value_cap'], $currency) : null;
        if ($cap !== null && $cap <= 0) {
            throw new DomainException('The value limit must be above zero (or empty for none).');
        }
        $lines = [];
        foreach (array_values($data['lines'] ?? []) as $i => $r) {
            $cost = Money::toMinor((string) ($r['unit_cost'] ?? '0'), $currency);
            $max = ($r['max_qty'] ?? null) !== null && $r['max_qty'] !== '' ? Qty::toMilli((string) $r['max_qty']) : null;
            if (! empty($r['product_uom_id'])) {
                $unit = ProductUom::with('uom')->findOrFail($r['product_uom_id']);
                $product = Product::findOrFail($unit->product_id);
                $base = ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'account_id' => null, 'name' => $product->name, 'uom_code' => $unit->uom->code];
            } else {
                $account = Account::find($r['account_id'] ?? null) ?? throw new DomainException('Choose a product, or the account for a service line.');
                $name = trim((string) ($r['description'] ?? ''));
                if ($name === '') {
                    throw new DomainException('Describe each service line.');
                }
                $base = ['product_id' => null, 'product_uom_id' => null, 'account_id' => $account->id, 'name' => mb_substr($name, 0, 160),
                    'uom_code' => mb_substr(trim((string) ($r['uom_code'] ?? '')) ?: 'EA', 0, 10)];
            }
            if ($cost <= 0) {
                throw new DomainException("{$base['name']}: enter the agreed price.");
            }
            if ($max !== null && $max <= 0) {
                throw new DomainException("{$base['name']}: the most that may be ordered must be above zero (or empty for no limit).");
            }
            $lines[] = ['line_no' => $i + 1] + $base + ['unit_cost_minor' => $cost, 'max_qty' => $max === null ? null : Qty::format($max),
                'note' => ($r['note'] ?? null) ? mb_substr(trim($r['note']), 0, 255) : null];
        }
        if ($lines === []) {
            throw new DomainException('Add at least one item with its agreed price.');
        }
        $keys = array_map(fn ($l) => $l['product_uom_id'] ?? 'acct:'.$l['account_id'].':'.mb_strtolower($l['name']), $lines);
        if (count($keys) !== count(array_unique($keys))) {
            throw new DomainException('An item is on the agreement twice.');
        }

        return $this->recorder->transaction(function () use ($actor, $a, $data, $supplier, $title, $starts, $ends, $cap, $currency, $lines) {
            $isNew = ! $a->exists;
            $a->forceFill(['supplier_id' => $supplier->id, 'kind' => $data['kind'], 'title' => mb_substr($title, 0, 160), 'starts_on' => $starts->toDateString(),
                'ends_on' => $ends->toDateString(), 'value_cap_minor' => $cap, 'terms' => trim((string) ($data['terms'] ?? '')) ?: null]
                + (array_key_exists('file_path', $data) ? ['file_path' => $data['file_path'], 'file_name' => $data['file_name'] ?? null] : []));
            if ($isNew) {
                $a->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'purchase_agreement'), 'currency_code' => $currency,
                    'status' => 'draft', 'created_by' => $actor->user?->id, 'created_by_name' => $actor->name]);
            }
            $this->recorder->save($actor, $a, $isNew ? 'purchase_agreement.created' : 'purchase_agreement.updated');
            PurchaseAgreementLine::where('purchase_agreement_id', $a->id)->delete();
            foreach ($lines as $l) {
                PurchaseAgreementLine::create(['purchase_agreement_id' => $a->id] + $l);
            }
            $this->recorder->audit->record($actor, 'purchase_agreement.lines_changed', $a, ['lines' => [null, count($lines)]]);

            return $a->fresh('lines');
        });
    }

    /** Put it in force: someone with "Approve purchase orders" other than who made it. */
    public function activate(Actor $actor, PurchaseAgreement $a): PurchaseAgreement
    {
        $this->require($actor, 'purchasing.approve');
        if ($a->status !== 'draft') {
            throw new DomainException("{$a->number} is not a draft.");
        }
        if ($a->created_by && $a->created_by === $actor->id) {
            throw new DomainException('You made this agreement, so someone else must put it in force.', 'own_document');
        }
        if ($a->ends_on->isBefore(today())) {
            throw new DomainException("{$a->number} has already ended ({$a->ends_on->format('j M Y')}).");
        }
        app(SupplierOnboarding::class)->assertCanOrder(Supplier::findOrFail($a->supplier_id));
        $a->forceFill(['status' => 'active', 'approved_by' => $actor->id, 'approved_by_name' => $actor->name, 'approved_at' => now()]);

        return $this->recorder->save($actor, $a, 'purchase_agreement.activated');
    }

    public function close(Actor $actor, PurchaseAgreement $a, string $reason): PurchaseAgreement
    {
        $this->require($actor, 'purchasing.manage');
        if ($a->status === 'closed') {
            throw new DomainException("{$a->number} is already closed.");
        }
        if (trim($reason) === '') {
            throw new DomainException('Give the reason.');
        }
        $a->forceFill(['status' => 'closed', 'closed_reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->save($actor, $a, 'purchase_agreement.closed', $reason);
    }

    /**
     * Order against the agreement: a draft purchase order at the agreed prices.
     *
     * @param  array<string, string>  $qtys  agreement line id => quantity (empty or 0 = not now)
     */
    public function release(Actor $actor, PurchaseAgreement $a, Warehouse $warehouse, array $qtys, ?string $expectedOn = null): PurchaseOrder
    {
        if (! $a->inForce()) {
            throw new DomainException("{$a->number} is not in force today.");
        }
        $rows = [];
        foreach ($a->lines as $l) {
            $q = trim((string) ($qtys[$l->id] ?? ''));
            if ($q === '' || Qty::toMilli($q) <= 0) {
                continue;
            }
            $rows[] = ['product_uom_id' => $l->product_uom_id, 'account_id' => $l->account_id, 'description' => $l->name, 'uom_code' => $l->uom_code,
                'qty' => $q, 'agreement_line_id' => $l->id];
        }
        if ($rows === []) {
            throw new DomainException('Enter a quantity for at least one item.');
        }

        return app(PurchaseOrderService::class)->saveDraft($actor, new PurchaseOrder, ['supplier_id' => $a->supplier_id, 'warehouse_id' => $warehouse->id,
            'agreement_id' => $a->id, 'expected_on' => $expectedOn, 'note' => "Against agreement {$a->number}", 'lines' => $rows]);
    }

    /** The agreement line that prices this item: on the given agreement, else any agreement with the supplier in force (cheapest). */
    public function priceFor(Supplier $supplier, ?PurchaseAgreement $agreement, ?string $unitId, ?string $accountId, ?string $lineId = null): ?PurchaseAgreementLine
    {
        $q = PurchaseAgreementLine::query()->whereIn('purchase_agreement_id', $agreement ? [$agreement->id]
            : PurchaseAgreement::where('supplier_id', $supplier->id)->where('status', 'active')->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->select('id'));
        if ($lineId) {
            return (clone $q)->whereKey($lineId)->first() ?? throw new DomainException('That item is not on the agreement.');
        }
        if ($unitId) {
            return $q->where('product_uom_id', $unitId)->orderBy('unit_cost_minor')->first();
        }

        return $agreement && $accountId ? $q->where('account_id', $accountId)->orderBy('line_no')->first() : null;
    }

    /**
     * Ordered so far on each line and in total: orders not cancelled count what they order (closed-short ones what came),
     * leaving out the order being changed.
     *
     * @return array{lines: array<string, int>, value: int} line id => milli-quantity; value before VAT
     */
    public function usage(PurchaseAgreement $a, ?string $exceptPoId = null): array
    {
        $rows = DB::table('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->join('purchase_agreement_lines as al', 'al.id', '=', 'l.agreement_line_id')->where('al.purchase_agreement_id', $a->id)
            ->where('o.status', '!=', 'cancelled')->when($exceptPoId, fn ($q) => $q->where('o.id', '!=', $exceptPoId))
            ->get(['l.agreement_line_id', 'l.qty', 'l.received_qty', 'l.unit_cost_minor', 'o.status']);
        $lines = [];
        $value = 0;
        foreach ($rows as $r) {
            $qty = Qty::toMilli((string) ($r->status === 'closed' ? $r->received_qty : $r->qty));
            $lines[$r->agreement_line_id] = ($lines[$r->agreement_line_id] ?? 0) + $qty;
            $value += Qty::divRound($qty * (int) $r->unit_cost_minor, 1000);
        }

        return ['lines' => $lines, 'value' => $value];
    }

    /** @param list<array<string, mixed>> $lines priced order lines */
    public function assertWithinCaps(array $lines, ?string $poId): void
    {
        $byAgreement = [];
        foreach ($lines as $l) {
            if ($l['agreement_line_id'] ?? null) {
                $byAgreement[PurchaseAgreementLine::whereKey($l['agreement_line_id'])->value('purchase_agreement_id')][] = $l;
            }
        }
        foreach ($byAgreement as $agreementId => $ls) {
            $a = PurchaseAgreement::with('lines')->findOrFail($agreementId);
            $used = $this->usage($a, $poId);
            $value = $used['value'];
            $qtys = [];
            foreach ($ls as $l) {
                $qtys[$l['agreement_line_id']] = ($qtys[$l['agreement_line_id']] ?? 0) + Qty::toMilli((string) $l['qty']);
                $value += (int) $l['net_minor'];
            }
            foreach ($qtys as $lineId => $qty) {
                $al = $a->lines->firstWhere('id', $lineId);
                if ($al?->max_qty !== null) {
                    $left = Qty::toMilli((string) $al->max_qty) - ($used['lines'][$lineId] ?? 0);
                    if ($qty > $left) {
                        throw new DomainException("{$al->name}: only ".Qty::format(max(0, $left))." {$al->uom_code} is left on {$a->number}.");
                    }
                }
            }
            if ($a->value_cap_minor !== null && $value > $a->value_cap_minor) {
                throw new DomainException("This would take {$a->number} to ".Money::display($value, $a->currency_code).', above its limit of '
                    .Money::display($a->value_cap_minor, $a->currency_code).'.');
            }
        }
    }

    /** Daily: tell the buyers once before an agreement in force ends, and once when it has ended. */
    public function endAlerts(string $tenantId): int
    {
        $days = (int) $this->settings->get('purchasing.document_warning_days', $tenantId);
        $n = 0;
        $due = PurchaseAgreement::withoutGlobalScopes()->with('supplier')->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereDate('ends_on', '<=', today()->addDays($days))->get();
        foreach ($due as $a) {
            $stage = $a->ends_on->isBefore(today()) ? 'ended' : 'soon';
            if ($a->alerted === $stage) {
                continue;
            }
            $this->notifier->toPermission($tenantId, 'purchasing.manage', null,
                $stage === 'ended' ? "Agreement {$a->number} with {$a->supplier?->name} has ended" : "Agreement {$a->number} with {$a->supplier?->name} ends {$a->ends_on->format('j M Y')}",
                $stage === 'ended' ? 'Its prices no longer apply to new orders. Close it, or agree a new one.' : 'Agree new prices in time, or close it.',
                url('/admin/purchase-agreements/'.$a->id));
            DB::table('purchase_agreements')->where('id', $a->id)->update(['alerted' => $stage]);
            $n++;
        }

        return $n;
    }

    private function require(Actor $actor, string $permission): void
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }
}
