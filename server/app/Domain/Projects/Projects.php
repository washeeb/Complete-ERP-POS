<?php

namespace App\Domain\Projects;

use App\Domain\Inventory\StockLedger;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Sales\InvoiceService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExpenseClaim;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Project;
use App\Models\ProjectBilling;
use App\Models\ProjectCost;
use App\Models\ProjectIssue;
use App\Models\ProjectMilestone;
use App\Models\ProjectRate;
use App\Models\ProjectTask;
use App\Models\SupplierBill;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Support\Collection;

/**
 * Projects and job costing (paid add-on "projects"; interface stage 3).
 *
 * A project is for a customer — billed at a fixed price by milestones, or for time and materials — or internal (not
 * billed). Its costs are collected by themselves into project_costs as they happen:
 *   - materials: stock issued from a store to the project (at its cost; returns come back at what was issued);
 *   - bought in: supplier bill lines charged to the project (services, non-stock items);
 *   - expenses: staff expense claims charged to it, when paid;
 *   - time: approved timesheet hours × each person's cost rate (management cost only: salaries are already in the books).
 * Every cost has what it is billed at: hours × the person's (or project's) rate; others at cost + the project's markup.
 *
 * The books carry the project on each line. A billed project's costs (materials, bought in, expenses) wait in "Project
 * work in progress" until they are invoiced — the billed items for time and materials, everything so far at each
 * milestone — or the project is closed; then they move to "Project costs". Invoices go to "Project and service income".
 */
final class Projects
{
    public const ADDON = 'projects';

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly LicenseManager $license,
        private readonly StockLedger $stock,
    ) {}

    public static function enabled(): bool
    {
        return app(LicenseManager::class)->hasAddon(self::ADDON);
    }

    // --- projects ------------------------------------------------------------------------------------------------------

    public function save(Actor $actor, Project $p, array $d): Project
    {
        $branch = Branch::find($d['branch_id'] ?? $p->branch_id) ?? throw new DomainException('Choose the branch the project belongs to.');
        $this->require($actor, 'projects.manage', $branch);
        if ($p->exists && ! $p->isOpen()) {
            throw new DomainException('This project is closed.');
        }
        $billing = $d['billing'] ?? $p->billing ?? 'time';
        if (! isset(Project::BILLING[$billing])) {
            throw new DomainException('Choose how the project is billed.');
        }
        if ($p->exists && $p->billing !== $billing && ProjectCost::where('project_id', $p->id)->exists()) {
            throw new DomainException('Costs are already on this project: how it is billed cannot change now.');
        }
        $name = trim((string) ($d['name'] ?? $p->name));
        if ($name === '') {
            throw new DomainException('Name the project.');
        }
        $customerId = array_key_exists('customer_id', $d) ? ($d['customer_id'] ?: null) : $p->customer_id;
        if ($billing !== 'internal' && ! $customerId) {
            throw new DomainException('A billed project needs its customer.');
        }
        if ($customerId && ! Customer::whereKey($customerId)->exists()) {
            throw new DomainException('Choose the customer.');
        }
        $productId = array_key_exists('service_product_id', $d) ? ($d['service_product_id'] ?: null) : $p->service_product_id;
        if ($billing !== 'internal') {
            $product = Product::find($productId) ?? throw new DomainException('Choose the service the invoices show for time, milestones and bought-in costs (e.g. “Professional services”).');
            if ($product->track_stock) {
                throw new DomainException("{$product->name} is a stock item: choose a service (not stock-tracked).");
            }
        }
        $manager = array_key_exists('manager_id', $d) ? ($d['manager_id'] ?: null) : ($p->exists ? $p->manager_id : $actor->id); // a new project: whoever starts it
        if ($manager && ($u = User::find($manager)) && ! $this->authz->allows($u, 'projects.view', $branch)) {
            throw new DomainException("{$u->name} cannot see projects of {$branch->name}.");
        }
        $int = fn (string $k, $cur) => array_key_exists($k, $d) ? ($d[$k] === null || $d[$k] === '' ? null : max(0, (int) $d[$k])) : $cur;

        return $this->recorder->transaction(function () use ($actor, $p, $d, $branch, $billing, $name, $customerId, $productId, $manager, $int) {
            $isNew = ! $p->exists;
            $p->forceFill([
                'name' => mb_substr($name, 0, 255), 'customer_id' => $customerId, 'branch_id' => $branch->id, 'manager_id' => $manager, 'billing' => $billing,
                'status' => isset(Project::STATUSES[$d['status'] ?? '']) && in_array($d['status'], ['planned', 'active', 'on_hold'], true) ? $d['status'] : ($p->status ?? 'active'),
                'starts_on' => array_key_exists('starts_on', $d) ? ($d['starts_on'] ?: null) : $p->starts_on,
                'ends_on' => array_key_exists('ends_on', $d) ? ($d['ends_on'] ?: null) : $p->ends_on,
                'contract_minor' => $billing === 'milestones' ? $int('contract_minor', $p->contract_minor) : null,
                'budget_cost_minor' => $int('budget_cost_minor', $p->budget_cost_minor),
                'hourly_rate_minor' => $billing === 'time' ? $int('hourly_rate_minor', $p->hourly_rate_minor) : null,
                'materials_markup_bp' => $billing === 'time' ? (int) ($int('materials_markup_bp', $p->materials_markup_bp) ?? 0) : 0,
                'service_product_id' => $billing === 'internal' ? null : $productId,
                'crm_deal_id' => array_key_exists('crm_deal_id', $d) ? ($d['crm_deal_id'] ?: null) : $p->crm_deal_id,
                'description' => array_key_exists('description', $d) ? $d['description'] : $p->description,
            ]);
            if ($isNew) {
                $p->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'project'),
                    'currency_code' => Tenant::find($actor->tenantId)->currency_code, 'created_by_name' => $actor->name]);
            }

            return $this->recorder->save($actor, $p, $isNew ? 'project.created' : 'project.updated', branchId: $branch->id);
        });
    }

    /**
     * Finish (or cancel) a project: what is still in work in progress moves to project costs. Unbilled costs stay
     * listed (and can still be billed by time and materials before closing).
     */
    public function close(Actor $actor, Project $p, string $status = 'done'): Project
    {
        $this->require($actor, 'projects.manage', $p->branch);
        if (! $p->isOpen()) {
            throw new DomainException('This project is already closed.');
        }
        if (! in_array($status, ['done', 'cancelled'], true)) {
            throw new DomainException('Close a project as finished or cancelled.');
        }

        return $this->recorder->transaction(function () use ($actor, $p, $status) {
            if ($p->isBilled()) {
                $this->release($p, ProjectCost::where('project_id', $p->id)->where('in_books', true)->whereNull('released_at')->get(), null, 'close');
            }
            ProjectTask::where('project_id', $p->id)->where('status', '<>', 'done')->update(['status' => 'done']);
            $p->forceFill(['status' => $status, 'closed_at' => now()]);

            return $this->recorder->save($actor, $p, 'project.'.$status, branchId: $p->branch_id);
        });
    }

    public function reopen(Actor $actor, Project $p): Project
    {
        $this->require($actor, 'projects.manage', $p->branch);
        if ($p->isOpen()) {
            return $p;
        }
        $p->forceFill(['status' => 'active', 'closed_at' => null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $p, 'project.reopened', branchId: $p->branch_id));
    }

    // --- tasks and milestones ------------------------------------------------------------------------------------------

    public function saveTask(Actor $actor, Project $p, ProjectTask $t, array $d): ProjectTask
    {
        $this->require($actor, 'projects.manage', $p->branch);
        $this->assertOpen($p);
        $name = trim((string) ($d['name'] ?? $t->name));
        if ($name === '') {
            throw new DomainException('Name the task.');
        }
        $assignee = array_key_exists('assigned_to', $d) ? ($d['assigned_to'] ?: null) : $t->assigned_to;
        if ($assignee && ($u = User::find($assignee)) && ! $this->authz->allows($u, 'projects.time', $p->branch)) {
            throw new DomainException("{$u->name} cannot log time on projects of this branch.");
        }
        $t->forceFill(['tenant_id' => $p->tenant_id, 'project_id' => $p->id, 'name' => mb_substr($name, 0, 255), 'assigned_to' => $assignee,
            'status' => isset(ProjectTask::STATUSES[$d['status'] ?? '']) ? $d['status'] : ($t->status ?? 'todo'),
            'due_on' => array_key_exists('due_on', $d) ? ($d['due_on'] ?: null) : $t->due_on,
            'estimate_hours' => array_key_exists('estimate_hours', $d) ? ($d['estimate_hours'] === '' || $d['estimate_hours'] === null ? null : max(0, (float) $d['estimate_hours'])) : $t->estimate_hours,
            'billable' => $p->isBilled() && (bool) ($d['billable'] ?? $t->billable ?? true),
            'sort' => (int) ($d['sort'] ?? $t->sort ?? 0)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $t, 'project_task.saved', branchId: $p->branch_id));
    }

    public function saveMilestone(Actor $actor, Project $p, ProjectMilestone $m, array $d): ProjectMilestone
    {
        $this->require($actor, 'projects.manage', $p->branch);
        $this->assertOpen($p);
        if ($p->billing !== 'milestones') {
            throw new DomainException('Milestones are for fixed-price projects.');
        }
        if ($m->exists && $m->status === 'invoiced') {
            throw new DomainException('This milestone is invoiced.');
        }
        $name = trim((string) ($d['name'] ?? $m->name));
        $amount = (int) ($d['amount_minor'] ?? $m->amount_minor ?? 0);
        if ($name === '' || $amount <= 0) {
            throw new DomainException('Name the milestone and give its amount (before tax).');
        }
        if ($p->contract_minor) {
            $others = (int) ProjectMilestone::where('project_id', $p->id)->when($m->exists, fn ($q) => $q->whereKeyNot($m->id))->sum('amount_minor');
            if ($others + $amount > $p->contract_minor) {
                throw new DomainException('The milestones would add up to more than the agreed price ('.Money::display($p->contract_minor, $p->currency_code).').');
            }
        }
        $m->forceFill(['tenant_id' => $p->tenant_id, 'project_id' => $p->id, 'name' => mb_substr($name, 0, 255), 'amount_minor' => $amount,
            'due_on' => array_key_exists('due_on', $d) ? ($d['due_on'] ?: null) : $m->due_on,
            'status' => in_array($d['status'] ?? '', ['pending', 'ready'], true) ? $d['status'] : ($m->status ?? 'pending'),
            'sort' => (int) ($d['sort'] ?? $m->sort ?? 0)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $m, 'project_milestone.saved', branchId: $p->branch_id));
    }

    // --- rates ---------------------------------------------------------------------------------------------------------

    public function saveRate(Actor $actor, User $user, int $costMinor, ?int $billMinor): ProjectRate
    {
        $this->requireAnywhere($actor, 'projects.manage');
        if ($costMinor < 0 || ($billMinor !== null && $billMinor < 0)) {
            throw new DomainException('Rates cannot be negative.');
        }
        $r = ProjectRate::where('user_id', $user->id)->first() ?? new ProjectRate;
        $r->forceFill(['tenant_id' => $actor->tenantId, 'user_id' => $user->id, 'cost_rate_minor' => $costMinor, 'bill_rate_minor' => $billMinor]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $r, 'project_rate.saved', $user->name));
    }

    /** @return array{cost: int, bill: int} per hour for this person on this project */
    public function ratesFor(Project $p, string $userId): array
    {
        $r = ProjectRate::where('user_id', $userId)->first();

        return ['cost' => (int) ($r?->cost_rate_minor ?? 0), 'bill' => (int) ($r?->bill_rate_minor ?? $p->hourly_rate_minor ?? 0)];
    }

    // --- costs ---------------------------------------------------------------------------------------------------------

    /**
     * Take stock out of a store for the project (or bring it back with $return).
     *
     * @param  list<array{product_uom_id: string, qty: string}>  $lines
     */
    public function issue(Actor $actor, Project $p, Warehouse $warehouse, array $lines, ?string $note = null, bool $return = false): ProjectIssue
    {
        $this->require($actor, 'projects.manage', $p->branch);
        if (! $actor->user || ! $this->authz->allows($actor->user, 'stock.adjust', $warehouse)) {
            throw new AccessDenied('Taking stock out of '.$warehouse->name.' needs the right to change its stock.');
        }
        $this->assertOpen($p);
        $entries = [];
        foreach ($lines as $l) {
            $unit = ProductUom::with(['product', 'uom'])->find($l['product_uom_id'] ?? null) ?? throw new DomainException('Choose the item and its unit.');
            $milli = Qty::toMilli((string) ($l['qty'] ?? '0'));
            if ($milli <= 0) {
                throw new DomainException("Enter how many {$unit->uom->code} of {$unit->product->name}.");
            }
            if (! $unit->product->track_stock) {
                throw new DomainException("{$unit->product->name} is not a stock item.");
            }
            $entry = ['product' => $unit->product, 'unit' => $unit, 'qty_milli' => $return ? $milli : -$milli];
            if ($return) { // back at what this project paid for it, so its costs clear exactly
                $out = ProjectCost::where('project_id', $p->id)->where('kind', 'material')->where('product_id', $unit->product_id);
                $qtyBase = (float) $out->sum('qty');
                if ($qtyBase * 1000 < $milli * (float) $unit->factor - 0.5) {
                    throw new DomainException("Only {$qtyBase} of {$unit->product->name} (in its base unit) went to this project.");
                }
                $entry['unit_cost_minor'] = $qtyBase > 0 ? (int) round((int) $out->sum('cost_minor') / $qtyBase * (float) $unit->factor) : null;
            }
            $entries[] = $entry;
        }
        if ($entries === []) {
            throw new DomainException('Add at least one item.');
        }

        return $this->recorder->transaction(function () use ($actor, $p, $warehouse, $entries, $note, $return) {
            $doc = (new ProjectIssue)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'project_issue'), 'project_id' => $p->id,
                'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'kind' => $return ? 'return' : 'issue',
                'note' => $note ? mb_substr(trim($note), 0, 255) : null, 'created_by_name' => $actor->name]);
            $doc->save();
            $movements = $this->stock->post($actor, $warehouse, $return ? 'project_return' : 'project_issue', $entries, "{$p->number}: ".($note ?: ($return ? 'returned' : 'issued')), 'project_issue', $doc->id);
            $total = 0;
            foreach ($movements as $mv) {
                $value = (int) round((float) $mv->qty_entered * (int) ($mv->unit_cost_minor ?? 0)); // signed: out is negative
                $cost = -$value;
                $total += $cost;
                $base = ProductUom::with('product')->where('product_id', $mv->product_id)->where('is_base', true)->first();
                $this->addCost($p, 'material', 'stock_movement', $mv->id, $mv->product_id, $base?->id,
                    ($return ? 'Returned: ' : '').$base?->product?->name, -(float) $mv->qty_base, $cost, now()->toDateString(), true);
            }
            $doc->forceFill(['value_minor' => $total]);

            return $this->recorder->save($actor, $doc, $return ? 'project.stock_returned' : 'project.stock_issued', branchId: $warehouse->branch_id);
        });
    }

    /** A supplier bill was posted: its lines charged to projects become their costs. */
    public function collectBill(SupplierBill $bill): void
    {
        foreach ($bill->lines()->whereNotNull('project_id')->get() as $l) {
            $p = Project::find($l->project_id);
            if ($p) {
                $this->addCost($p, 'purchase', 'supplier_bill_line', $l->id, null, null, $l->description, 1, (int) $l->net_minor, $bill->bill_date->toDateString(), true);
            }
        }
    }

    /** An expense claim charged to a project was paid. */
    public function collectExpense(ExpenseClaim $claim): void
    {
        if ($claim->project_id && ($p = Project::find($claim->project_id))) {
            $this->addCost($p, 'expense', 'expense_claim', $claim->id, null, null, "{$claim->number} {$claim->description}", 1, (int) $claim->amount_minor,
                ($claim->paid_on ?? now())->toDateString(), true);
        }
    }

    /** Approved time: hours × the person's cost rate (not in the books: their pay already is). */
    public function collectTime(TimeEntry $e): void
    {
        $p = Project::find($e->project_id);
        $rates = $this->ratesFor($p, $e->user_id);
        $hours = $e->minutes / 60;
        $this->addCost($p, 'time', 'time_entry', $e->id, null, null, trim(($e->user?->name ?? '').': '.($e->task?->name ?? $e->note ?? 'time')), round($hours, 3),
            (int) round($hours * $rates['cost']), $e->work_date->toDateString(), false, $e->billable ? (int) round($hours * $rates['bill']) : 0, $e->billable);
    }

    private function addCost(Project $p, string $kind, string $sourceType, string $sourceId, ?string $productId, ?string $uomId, ?string $description, float $qty, int $cost,
        string $on, bool $inBooks, ?int $bill = null, bool $billable = true): void
    {
        $billable = $billable && $p->isBilled();
        $bill ??= $p->billing === 'time' ? (int) round($cost * (10000 + $p->materials_markup_bp) / 10000) : 0;
        ProjectCost::updateOrCreate(['source_type' => $sourceType, 'source_id' => $sourceId, 'kind' => $kind], [
            'tenant_id' => $p->tenant_id, 'project_id' => $p->id, 'product_id' => $productId, 'product_uom_id' => $uomId,
            'description' => mb_substr((string) ($description ?: ProjectCost::KINDS[$kind]), 0, 255), 'qty' => round($qty, 3), 'cost_minor' => $cost,
            'bill_minor' => $billable ? $bill : 0, 'billable' => $billable, 'in_books' => $inBooks, 'occurred_on' => $on,
        ]);
    }

    // --- billing -------------------------------------------------------------------------------------------------------

    /** Invoice a milestone; everything in work in progress so far moves to project costs (matched to this income). */
    public function invoiceMilestone(Actor $actor, ProjectMilestone $m): Invoice
    {
        $p = $m->project;
        $this->require($actor, 'projects.bill', $p->branch);
        if ($m->status === 'invoiced') {
            throw new DomainException('This milestone is already invoiced.');
        }

        return $this->recorder->transaction(function () use ($actor, $p, $m) {
            $invoice = $this->raise($actor, $p, [[$p->serviceProduct, "{$p->name}: {$m->name}", 1000, $m->amount_minor]]);
            $m->forceFill(['status' => 'invoiced', 'invoice_id' => $invoice->id]);
            $this->recorder->save($actor, $m, 'project_milestone.invoiced', $invoice->number, $p->branch_id);
            $this->release($p, ProjectCost::where('project_id', $p->id)->where('in_books', true)->whereNull('released_at')->get(), $invoice, 'invoice');

            return $invoice;
        });
    }

    /** @return Collection<int, ProjectCost> billable costs not invoiced yet (time and materials) */
    public function unbilled(Project $p): Collection
    {
        return ProjectCost::where('project_id', $p->id)->where('billable', true)->whereNull('invoice_id')->orderBy('occurred_on')->get();
    }

    /**
     * Invoice time and materials: the chosen unbilled costs (all when none are chosen), materials as their products,
     * time and bought-in costs as the project's service. Their costs move from work in progress to project costs.
     *
     * @param  list<string>|null  $costIds
     */
    public function invoiceTime(Actor $actor, Project $p, ?array $costIds = null): Invoice
    {
        $this->require($actor, 'projects.bill', $p->branch);
        if ($p->billing !== 'time') {
            throw new DomainException('Only time-and-materials projects are invoiced from their costs.');
        }
        $costs = $this->unbilled($p)->when($costIds !== null, fn ($c) => $c->whereIn('id', $costIds))->values();
        if ($costs->isEmpty()) {
            throw new DomainException('Nothing to invoice: no unbilled time or costs.');
        }

        return $this->recorder->transaction(function () use ($actor, $p, $costs) {
            $lines = [];
            foreach ($costs->where('kind', 'time')->groupBy(fn ($c) => $c->bill_minor && $c->qty > 0 ? (string) round($c->bill_minor / $c->qty) : '0') as $rate => $group) {
                $hours = (float) $group->sum('qty');
                $lines[] = [$p->serviceProduct, "{$p->name}: ".rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').' hour(s)', (int) round($hours * 1000), (int) $group->sum('bill_minor')];
            }
            foreach ($costs->where('kind', 'material')->groupBy('product_id') as $productId => $group) { // net of what came back
                $milli = (int) round((float) $group->sum('qty') * 1000);
                if ($milli > 0) {
                    $product = Product::find($productId);
                    $lines[] = [$product, $product?->name ?? $group->first()->description, $milli, (int) $group->sum('bill_minor')];
                }
            }
            foreach ($costs->whereIn('kind', ['purchase', 'expense']) as $c) {
                $lines[] = [$p->serviceProduct, mb_substr("{$p->name}: {$c->description}", 0, 255), 1000, (int) $c->bill_minor];
            }
            $invoice = $this->raise($actor, $p, array_values(array_filter($lines, fn ($l) => $l[3] !== 0)));
            ProjectCost::whereIn('id', $costs->pluck('id'))->update(['invoice_id' => $invoice->id]);
            $this->release($p, $costs->where('in_books', true)->whereNull('released_at'), $invoice, 'invoice');

            return $invoice;
        });
    }

    /**
     * @param  list<array{0: ?Product, 1: string, 2: int, 3: int, 4?: ?string}>  $items  product, text, qty (milli, base unit), amount before tax
     */
    private function raise(Actor $actor, Project $p, array $items): Invoice
    {
        if (! $p->isBilled() || ! $p->customer_id) {
            throw new DomainException('Internal projects are not invoiced.');
        }
        if ($items === []) {
            throw new DomainException('Nothing to invoice.');
        }
        $lines = [];
        foreach ($items as [$product, $text, $milli, $net]) {
            $product ??= $p->serviceProduct ?? throw new DomainException('Choose the service the project’s invoices show.');
            $unit = ProductUom::with('uom')->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
            $rates = $product->taxGroup?->rates->where('is_active', true)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() ?? [];
            $t = TaxCalculator::line($net, $rates, false);
            $lines[] = ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => mb_substr($text, 0, 255), 'uom_code' => $unit->uom->code,
                'factor' => '1', 'qty' => Qty::format($milli), 'unit_price_minor' => $milli > 0 ? (int) round($net * 1000 / $milli) : $net,
                'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown']];
        }

        return app(InvoiceService::class)->raise($actor, Customer::findOrFail($p->customer_id), $lines,
            ['branch_id' => $p->branch_id, 'currency_code' => $p->currency_code, 'prices_include_tax' => false, 'customer_ref' => $p->number, 'project_id' => $p->id]);
    }

    /** Costs in the books leave work in progress for project costs (posted by the automatic posting). */
    private function release(Project $p, Collection $costs, ?Invoice $invoice, string $reason): void
    {
        $amount = (int) $costs->sum('cost_minor');
        if ($costs->isEmpty()) {
            return;
        }
        ProjectCost::whereIn('id', $costs->pluck('id'))->update(['released_at' => now()]);
        if ($amount !== 0) {
            (new ProjectBilling)->forceFill(['tenant_id' => $p->tenant_id, 'project_id' => $p->id, 'branch_id' => $p->branch_id, 'invoice_id' => $invoice?->id,
                'reason' => $reason, 'released_minor' => $amount, 'released_on' => now()->toDateString()])->save();
        }
    }

    // --- figures -------------------------------------------------------------------------------------------------------

    /**
     * @return array{cost: int, by_kind: array<string, int>, hours: float, invoiced: int, unbilled: int, budget: ?int, profit: int, margin: ?float, progress: ?int}
     */
    public function figures(Project $p): array
    {
        $costs = ProjectCost::where('project_id', $p->id)->get(['kind', 'cost_minor', 'bill_minor', 'qty', 'invoice_id', 'billable']);
        $invoiced = (int) Invoice::where('project_id', $p->id)->sum('net_minor');
        $cost = (int) $costs->sum('cost_minor');
        $tasks = ProjectTask::where('project_id', $p->id)->get(['status']);

        return [
            'cost' => $cost, 'by_kind' => collect(ProjectCost::KINDS)->map(fn ($l, $k) => (int) $costs->where('kind', $k)->sum('cost_minor'))->all(),
            'hours' => (float) $costs->where('kind', 'time')->sum('qty'), 'invoiced' => $invoiced,
            'unbilled' => (int) $costs->where('billable', true)->whereNull('invoice_id')->sum('bill_minor'),
            'budget' => $p->budget_cost_minor, 'profit' => $invoiced - $cost, 'margin' => $invoiced > 0 ? round(($invoiced - $cost) * 100 / $invoiced, 1) : null,
            'progress' => $tasks->count() ? (int) round($tasks->where('status', 'done')->count() * 100 / $tasks->count()) : null,
        ];
    }

    // --- helpers -------------------------------------------------------------------------------------------------------

    private function assertOpen(Project $p): void
    {
        if (! $p->isOpen()) {
            throw new DomainException("Project {$p->number} is closed.");
        }
    }

    public function require(Actor $actor, string $permission, ?Branch $branch): void
    {
        $this->assertLicensed();
        if (! $actor->user || ! ($branch ? $this->authz->allows($actor->user, $permission, $branch) : $this->authz->allowsAnywhere($actor->user, $permission))) {
            throw new AccessDenied;
        }
    }

    private function requireAnywhere(Actor $actor, string $permission): void
    {
        $this->require($actor, $permission, null);
    }

    public function assertLicensed(): void
    {
        if (! $this->license->hasAddon(self::ADDON)) {
            throw new DomainException('Projects is a paid add-on: ask SyncLytics to add it to your licence.');
        }
    }
}
