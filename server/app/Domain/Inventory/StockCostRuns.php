<?php

namespace App\Domain\Inventory;

use App\Domain\Catalog\UnitChangeService;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockCostRun;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Progress;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cost runs (owner request 2026-11-05): a client's products were loaded without a cost, so their stock is worth nothing
 * in the books and every sale cost nothing. A run works a cost out from the selling price — the retail method of IAS 2,
 * which stands in for cost when real costs are unknown — for all products, a selection, or by category, brand, supplier
 * or store; a different factor can be set per category or brand.
 *
 * Prepare → the lines are the preview, with the products it could not cost and why. Submit → someone else approves
 * (Settings → Stock, on by default) → posted. Lines are worked out again at posting, against what is on hand by then.
 *
 * Stock stays a ledger: in each store the stock goes out at what the books hold for it and back in at the new cost, per
 * batch and bin (a pair of cost_set or cost_change movements), so quantities never change, the average follows by itself
 * and the books move by the difference: no cost → against Opening balances, a replaced cost → Stock losses and count
 * differences, or the account chosen on the run.
 *
 * Top-up: a delivery on stock that had no cost makes its cost the average of all of it, while the books hold only the
 * delivered goods, so the cost is right but the value is short; the run brings the books up to it without touching the cost.
 *
 * Catch-up: sales made before the run cost nothing and stay as they are. The run adds the estimated cost of those
 * goods in one entry per branch (cost of sales against the same account), counted once however many runs follow.
 *
 * Selling prices are never changed by a run, and pricing rules do not react to it: the cost came from the price.
 */
final class StockCostRuns
{
    private const SETTING_TWO_PEOPLE = 'stock.cost_run_two_people';

    /** Movements that stand for "no real cost was known": the run's own movements are not counted. */
    private const COST_TYPES = ['cost_set', 'cost_change'];

    /** Books short of the cost by less than this (minor units) is rounding, not a top-up. */
    private const TOP_UP_FROM_MINOR = 100;

    private const CHUNK = 200;

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers,
        private readonly StockLedger $ledger, private readonly UnitChangeService $units, private readonly Settings $settings, private readonly GeneralLedger $gl) {}

    /** The cost of one base unit from its selling price per base unit, in minor units, half up. */
    public static function costFrom(string $priceMinor, string $method, int $valueBp): int
    {
        $cost = $method === 'divide' ? bcdiv(bcmul($priceMinor, '10000', 8), (string) $valueBp, 8) : bcdiv(bcmul($priceMinor, (string) (10000 - $valueBp), 8), '10000', 8);

        return (int) bcadd($cost, '0.5', 0);
    }

    /** The price a run works from: what the customer pays, or that without tax, whichever way the list stores it. */
    public static function priceUsed(string $stored, int $taxBp, bool $listIncludesTax, string $taxMode): string
    {
        return match (true) {
            $taxMode === 'shelf' && ! $listIncludesTax => bcdiv(bcmul($stored, (string) (10000 + $taxBp), 8), '10000', 8),
            $taxMode === 'remove_tax' && $listIncludesTax => bcdiv(bcmul($stored, '10000', 8), (string) (10000 + $taxBp), 8),
            default => $stored,
        };
    }

    public function twoPeople(string $tenantId): bool
    {
        return (bool) $this->settings->get(self::SETTING_TWO_PEOPLE, $tenantId);
    }

    /**
     * @param  array{method: string, value: string|float, tax_mode?: string, price_list_id?: ?string, overrides?: list<array{kind: string, id: string, value: string|float}>,
     *     product_ids?: list<string>, category_ids?: list<string>, brand_ids?: list<string>, supplier_ids?: list<string>, warehouse_ids?: list<string>,
     *     overwrite?: bool, catch_up?: bool, effective_on?: ?string, account_id?: ?string, note?: ?string}  $d
     */
    public function prepare(Actor $actor, array $d): StockCostRun
    {
        $this->require($actor, 'stock.cost.manage');
        $method = (string) ($d['method'] ?? '');
        if (! array_key_exists($method, StockCostRun::METHODS)) {
            throw new DomainException('Choose how the cost is worked out.');
        }
        $value = self::valueBp($method, $d['value'] ?? null);
        $list = PriceList::when($d['price_list_id'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('is_default', true))->where('is_active', true)->first()
            ?? throw new DomainException('Choose the price list to work from.');
        $tax = (string) ($d['tax_mode'] ?? 'shelf');
        if (! array_key_exists($tax, StockCostRun::TAX_MODES)) {
            throw new DomainException('Choose how tax is treated.');
        }
        $date = Carbon::parse($d['effective_on'] ?? now())->toDateString();
        $this->gl->postingDate($actor->tenantId, $date); // a closed month is refused here, not at the end
        $account = null;
        if ($d['account_id'] ?? null) {
            $account = Account::where('is_header', false)->where('is_active', true)->whereNull('control')->find($d['account_id'])
                ?? throw new DomainException('Choose an account that takes postings and is not a control account.');
            $this->require($actor, 'finance.view'); // the books' side of a run is an accountant's call
        }
        $overrides = [];
        foreach ($d['overrides'] ?? [] as $o) {
            if (! in_array($o['kind'] ?? '', ['category', 'brand'], true) || empty($o['id'])) {
                continue;
            }
            $name = DB::table($o['kind'] === 'category' ? 'categories' : 'brands')->where('tenant_id', $actor->tenantId)->where('id', $o['id'])->value('name')
                ?? throw new DomainException('That category or brand is not in this company.');
            $overrides[] = ['kind' => $o['kind'], 'id' => $o['id'], 'name' => $name, 'value_bp' => self::valueBp($method, $o['value'] ?? null)];
        }

        $allowed = $this->authz->branchIds($actor->user, 'stock.cost.manage');
        $chosen = $d['warehouse_ids'] ?? [];
        if ($allowed !== null) { // a run covers only the stores this person looks after; fixed here, so the approver posts the same stores
            $mine = Warehouse::whereIn('branch_id', $allowed)->pluck('id')->all();
            $d['warehouse_ids'] = $chosen === [] ? $mine : array_values(array_intersect($chosen, $mine));
            if ($d['warehouse_ids'] === []) {
                throw new AccessDenied;
            }
        }

        return $this->recorder->transaction(function () use ($actor, $d, $method, $value, $list, $tax, $date, $account, $overrides) {
            $run = (new StockCostRun)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'stock_cost_run'), 'status' => 'draft',
                'method' => $method, 'value_bp' => $value, 'overrides' => $overrides, 'tax_mode' => $tax, 'price_list_id' => $list->id,
                'scope' => array_filter(array_intersect_key($d, array_flip(['product_ids', 'category_ids', 'brand_ids', 'supplier_ids', 'warehouse_ids']))),
                'overwrite' => (bool) ($d['overwrite'] ?? false), 'catch_up' => (bool) ($d['catch_up'] ?? true), 'effective_on' => $date, 'account_id' => $account?->id,
                'currency_code' => Tenant::find($actor->tenantId)->currency_code, 'note' => ($n = trim((string) ($d['note'] ?? ''))) !== '' ? mb_substr($n, 0, 255) : null,
                'created_by' => $actor->id, 'created_by_name' => $actor->name]);
            $this->recorder->save($actor, $run, 'stock_cost_run.prepared', "{$run->number}: ".StockCostRun::factorLabel($method, $value));
            $this->store($run, $this->plan($run));

            return $run->fresh();
        });
    }

    /** Works the lines out again: stock and prices may have moved since the run was prepared. */
    public function recalculate(Actor $actor, StockCostRun $run): StockCostRun
    {
        $this->require($actor, 'stock.cost.manage');
        $this->mustBeOpen($run);

        return $this->recorder->transaction(function () use ($run) {
            $this->store($run, $this->plan($run));

            return $run->fresh();
        });
    }

    /**
     * A run that left products alone because they already had a cost: replace their costs too, and work it out again
     * (owner 2026-11-09: a run for chosen products showed nothing to do, because those products already had a cost).
     */
    public function replaceExisting(Actor $actor, StockCostRun $run): StockCostRun
    {
        $this->require($actor, 'stock.cost.manage');
        $this->mustBeOpen($run);

        return $this->recorder->transaction(function () use ($actor, $run) {
            $run->overwrite = true;
            $this->recorder->save($actor, $run, 'stock_cost_run.updated', 'Also replace costs products already have');
            $this->store($run, $this->plan($run));

            return $run->fresh();
        });
    }

    public function submit(Actor $actor, StockCostRun $run): StockCostRun
    {
        $this->require($actor, 'stock.cost.manage');
        if ($run->status !== 'draft') {
            throw new DomainException('Only a draft can be sent for approval.');
        }
        $this->mustHaveLines($run);
        $run->forceFill(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_by_name' => $actor->name, 'submitted_at' => now()]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $run, 'stock_cost_run.submitted', $run->number));
    }

    public function cancel(Actor $actor, StockCostRun $run, string $reason): StockCostRun
    {
        $this->require($actor, 'stock.cost.manage');
        $this->mustBeOpen($run);
        if (trim($reason) === '') {
            throw new DomainException('Give the reason.');
        }
        $run->forceFill(['status' => 'cancelled', 'reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $run, 'stock_cost_run.cancelled', "{$run->number}: {$reason}"));
    }

    /** May this person post it? A reason why not, or null. */
    public function whyNotPost(Actor $actor, StockCostRun $run): ?string
    {
        $two = $this->twoPeople($run->tenant_id);
        if ($two && $run->status === 'draft') {
            return 'Send it for approval first.';
        }
        if ($two && in_array($actor->id, array_filter([$run->created_by, $run->submitted_by]), true)) {
            return 'Someone else must approve it: nobody approves their own run.';
        }

        return null;
    }

    public function post(Actor $actor, StockCostRun $run): StockCostRun
    {
        $this->require($actor, 'stock.cost.approve');
        $this->mustBeOpen($run);
        if ($why = $this->whyNotPost($actor, $run)) {
            throw new DomainException($why);
        }
        $this->gl->postingDate($run->tenant_id, $run->effective_on->toDateString());
        @set_time_limit(0); // a whole catalogue, all in one transaction

        return $this->recorder->transaction(function () use ($actor, $run) {
            $run = StockCostRun::lockForUpdate()->findOrFail($run->id);
            $this->mustBeOpen($run);
            $this->store($run, $this->plan($run));
            $this->mustHaveLines($run);
            $lines = $run->lines()->where('kind', '<>', 'skipped')->get();
            foreach ($lines->pluck('branch_id')->unique() as $branchId) {
                $this->requireBranch($actor, 'stock.cost.approve', $branchId);
            }
            $this->move($actor, $run, $lines->where('on_hand', '<>', 0));
            $run->forceFill(['status' => 'posted', 'posted_by' => $actor->id, 'posted_by_name' => $actor->name, 'posted_at' => now(),
                'stock_value_minor' => $this->valueMoved($run)]);

            return $this->recorder->save($actor, $run, 'stock_cost_run.posted', "{$run->number}: stock value {$run->stock_value_minor}, cost of past sales {$run->catch_up_minor}");
        });
    }

    /**
     * Undo: another run, equal and opposite, that restores the earlier costs. A product whose cost has moved since (a
     * real delivery came in) keeps it: undoing would throw that away. Costs on sales made after the run stay as recorded.
     */
    public function undo(Actor $actor, StockCostRun $run, string $reason): StockCostRun
    {
        $this->require($actor, 'stock.cost.approve');
        if ($run->status !== 'posted' || $run->reverses_id) {
            throw new DomainException('Only a posted run can be undone.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give the reason.');
        }
        $date = now()->toDateString();
        $this->gl->postingDate($run->tenant_id, $date);
        @set_time_limit(0);

        return $this->recorder->transaction(function () use ($actor, $run, $reason, $date) {
            $run = StockCostRun::lockForUpdate()->findOrFail($run->id);
            $back = (new StockCostRun)->forceFill(['tenant_id' => $run->tenant_id, 'number' => $this->numbers->next($run->tenant_id, 'stock_cost_run'), 'status' => 'posted',
                'method' => $run->method, 'value_bp' => $run->value_bp, 'tax_mode' => $run->tax_mode, 'price_list_id' => $run->price_list_id, 'scope' => $run->scope,
                'overwrite' => $run->overwrite, 'catch_up' => $run->catch_up, 'effective_on' => $date, 'account_id' => $run->account_id, 'reverses_id' => $run->id,
                'currency_code' => $run->currency_code, 'note' => "Undoes {$run->number}", 'reason' => mb_substr(trim($reason), 0, 255), 'created_by' => $actor->id,
                'created_by_name' => $actor->name, 'posted_by' => $actor->id, 'posted_by_name' => $actor->name, 'posted_at' => now()]);
            $this->recorder->save($actor, $back, 'stock_cost_run.undo_posted', "{$back->number} undoes {$run->number}: {$reason}");

            $levels = DB::table('stock_levels')->where('tenant_id', $run->tenant_id)->whereIn('product_id', $run->lines()->pluck('product_id')->unique())->get()
                ->keyBy(fn ($l) => "{$l->warehouse_id}|{$l->product_id}");
            $held = $this->booksHold($run->tenant_id);
            $rows = [];
            foreach ($run->lines()->where('kind', '<>', 'skipped')->get() as $l) {
                $this->requireBranch($actor, 'stock.cost.approve', $l->branch_id);
                $level = $levels["{$l->warehouse_id}|{$l->product_id}"] ?? null;
                $onHand = $level ? Qty::toMilli((string) $level->on_hand) : 0;
                $moved = $l->on_hand != 0 && ($level === null || (int) round((float) $level->avg_cost_minor) !== $l->new_cost_minor);
                $row = ['product_id' => $l->product_id, 'warehouse_id' => $l->warehouse_id, 'branch_id' => $l->branch_id, 'kind' => $l->kind, 'on_hand' => '0', 'sold_qty' => bcmul((string) $l->sold_qty, '-1', 3),
                    'price_minor' => $l->price_minor, 'value_bp' => $l->value_bp, 'old_avg_minor' => $l->old_avg_minor, 'new_cost_minor' => $l->new_cost_minor,
                    'catch_up_minor' => -$l->catch_up_minor, 'skip_reason' => null];
                if ($moved) {
                    $row += ['stock_value_minor' => 0, 'value_before_minor' => 0];
                    $row['skip_reason'] = 'Its cost has changed since: it was not undone';
                } elseif ($onHand <= 0 || (float) $l->on_hand == 0) {
                    $row += ['stock_value_minor' => 0, 'value_before_minor' => 0];
                } else {
                    $before = $held["{$l->warehouse_id}|{$l->product_id}"] ?? 0;
                    $unitAfter = Qty::divRound($l->value_before_minor * 1000, Qty::toMilli((string) $l->on_hand)); // what one unit stood at in the books before the run
                    $row['on_hand'] = Qty::format($onHand);
                    $row['value_before_minor'] = $before;
                    $row['stock_value_minor'] = Qty::divRound($unitAfter * $onHand, 1000) - $before;
                    $row['new_cost_minor'] = $unitAfter;
                }
                $rows[] = $row;
            }
            $this->store($back, $rows);
            $this->move($actor, $back, $back->lines()->where('on_hand', '<>', 0)->get(), restoreAverage: true);
            $back->forceFill(['stock_value_minor' => $this->valueMoved($back)])->save();

            $run->forceFill(['status' => 'reversed', 'reason' => mb_substr(trim($reason), 0, 255)]);

            return $this->recorder->save($actor, $run, 'stock_cost_run.undone', "{$run->number}: {$reason}");
        });
    }

    /** What the run's movements really changed the stock value by (each movement rounds to the pesewa). */
    private function valueMoved(StockCostRun $run): int
    {
        return (int) DB::table('stock_movements')->where('source_type', 'stock_cost_run')->where('source_id', $run->id)
            ->selectRaw('COALESCE(SUM(ROUND(qty_entered * COALESCE(unit_cost_minor, 0))), 0)::bigint AS v')->value('v');
    }

    /** Out at what the books hold, back in at the new cost, per store, batch and bin; records what actually moved. */
    private function move(Actor $actor, StockCostRun $run, $lines, bool $restoreAverage = false): void
    {
        $when = Carbon::parse($run->effective_on->toDateString().' 12:00:00');
        $products = Product::with('units')->whereIn('id', $lines->pluck('product_id')->unique())->get()->keyBy('id');
        Progress::start("Posting {$run->number}", $lines->count(), $actor->id);
        $done = 0;
        foreach ($lines->groupBy('warehouse_id') as $warehouseId => $group) {
            $warehouse = Warehouse::findOrFail($warehouseId);
            foreach ($group->sortBy('product_id')->chunk(self::CHUNK) as $chunk) {
                foreach ([['cost_set', ['estimate', 'top_up']], ['cost_change', ['overwrite']]] as [$type, $kinds]) {
                    $outs = $ins = [];
                    foreach ($chunk->whereIn('kind', $kinds) as $l) {
                        $product = $products[$l->product_id];
                        $unit = $product->units->firstWhere('is_base', true);
                        $onHand = Qty::toMilli((string) $l->on_hand);
                        $unitBefore = Qty::divRound($l->value_before_minor * 1000, $onHand);
                        $lineOuts = [];
                        foreach ($this->units->stockPlan($product)[$warehouseId] ?? [] as $e) {
                            $common = ['product' => $product, 'unit' => $unit, 'batch_id' => $e['batch_id'], 'bin_id' => $e['bin_id'] ?? false];
                            $lineOuts[] = $common + ['qty_milli' => -$e['milli'], 'unit_cost_minor' => $unitBefore];
                            $ins[] = $common + ['qty_milli' => $e['milli'], 'unit_cost_minor' => (int) $l->new_cost_minor];
                        }
                        array_push($outs, ...self::toThePesewa($lineOuts, $l->value_before_minor));
                    }
                    if ($outs === []) {
                        continue;
                    }
                    $this->ledger->post($actor, $warehouse, $type, $outs, $run->number, 'stock_cost_run', $run->id, $when, alreadyHappened: true);
                    $this->ledger->post($actor, $warehouse, $type, $ins, $run->number, 'stock_cost_run', $run->id, $when, alreadyHappened: true);
                }
                // A cost that was already right (a top-up) or is being put back stays exactly as it was, not rounded to the pesewa.
                foreach ($chunk as $l) {
                    if ($restoreAverage || $l->kind === 'top_up') {
                        DB::table('stock_levels')->where('warehouse_id', $l->warehouse_id)->where('product_id', $l->product_id)
                            ->update(['avg_cost_minor' => $l->old_avg_minor, 'updated_at' => now()]);
                    }
                }
                Progress::tick($done += $chunk->count(), null, $actor->id);
            }
        }
        Progress::finish($actor->id);
    }

    /** Costs are whole pesewas per unit: one unit of the largest entry takes the rounding, so what goes out is exactly what the books held. */
    private static function toThePesewa(array $outs, int $value): array
    {
        $rest = $value - array_sum(array_map(fn ($o) => Qty::divRound(-$o['qty_milli'] * $o['unit_cost_minor'], 1000), $outs));
        usort($outs, fn ($a, $b) => $a['qty_milli'] <=> $b['qty_milli']); // out, so the largest is the most negative
        if ($rest !== 0 && $outs !== [] && $outs[0]['qty_milli'] <= -1000) {
            $one = ['qty_milli' => -1000, 'unit_cost_minor' => $outs[0]['unit_cost_minor'] + $rest] + $outs[0];
            $outs[0]['qty_milli'] += 1000;
            $outs = $outs[0]['qty_milli'] === 0 ? [$one, ...array_slice($outs, 1)] : [...$outs, $one];
        }

        return $outs;
    }

    /**
     * The preview: a line per product and store with work to do, and one per product it cannot cost.
     *
     * @return list<array<string, mixed>>
     */
    private function plan(StockCostRun $run): array
    {
        $scope = $run->scope ?? [];
        $tenantId = $run->tenant_id;
        $warehouses = Warehouse::query()->when($scope['warehouse_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))->get()->keyBy('id');
        $products = Product::with(['units', 'taxGroup.rates'])->where('track_stock', true)
            ->when($scope['product_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($scope['category_ids'] ?? null, fn ($q, $ids) => $q->whereIn('category_id', $ids))
            ->when($scope['brand_ids'] ?? null, fn ($q, $ids) => $q->whereIn('brand_id', $ids))
            ->when($scope['supplier_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', DB::table('supplier_products')->whereIn('supplier_id', $ids)->select('product_id')))
            ->orderBy('name')->get();
        $levels = DB::table('stock_levels')->where('tenant_id', $tenantId)->whereIn('warehouse_id', $warehouses->keys())->get()->groupBy('product_id');
        $held = $this->booksHold($tenantId);
        $inBatches = DB::table('stock_batch_levels')->where('tenant_id', $tenantId)->groupBy('warehouse_id', 'product_id')->selectRaw("warehouse_id || '|' || product_id AS k, SUM(on_hand) AS q")->pluck('q', 'k');
        $noCostIn = $this->receivedAtNoCost($tenantId);
        $sold = $run->catch_up ? $this->soldAtNoCost($tenantId) : [];
        $prices = $this->prices($run);
        $list = PriceList::findOrFail($run->price_list_id);

        $out = [];
        $costed = 0;
        foreach ($products as $p) {
            $working = [];
            $reason = match (true) {
                $p->consignor_id !== null => 'On consignment: its cost is what the consignor is paid',
                (bool) $p->track_serials => 'Serial numbers: its cost comes with each unit',
                default => null,
            };
            foreach ($levels[$p->id] ?? [] as $l) {
                $key = "{$l->warehouse_id}|{$p->id}";
                $onHand = Qty::toMilli((string) $l->on_hand);
                $soldMilli = $sold[$key] ?? 0;
                $avg = $l->avg_cost_minor === null ? null : (string) $l->avg_cost_minor;
                $missing = $avg === null || bccomp($avg, '0', 4) <= 0;
                if ($onHand === 0 && $soldMilli === 0) {
                    continue;
                }
                $working[] = compact('l', 'key', 'onHand', 'soldMilli', 'avg', 'missing');
            }
            if ($working === []) {
                continue;
            }
            if ($reason) {
                $out[] = $this->skip($p, $reason);

                continue;
            }
            $bp = $this->factorFor($run, $p);
            $taxBp = (int) ($p->taxGroup?->rates->where('is_active', true)->sum('rate_bp') ?? 0);
            $stored = $this->storedPrice($p, $prices[$p->id] ?? []);
            $used = $stored === null ? null : self::priceUsed($stored, $taxBp, (bool) $list->prices_include_tax, $run->tax_mode);
            $priced = $used === null ? null : self::costFrom($used, $run->method, $bp);
            $reported = false;
            foreach ($working as ['l' => $l, 'key' => $key, 'onHand' => $onHand, 'soldMilli' => $soldMilli, 'avg' => $avg, 'missing' => $missing]) {
                $wh = $warehouses[$l->warehouse_id];
                if ($onHand < 0) {
                    $out[] = $this->skip($p, 'Stock is below zero here: count it first, then run again', $wh);

                    continue;
                }
                if ($p->track_batches && $onHand > 0 && ($wh->use_bins || Qty::toMilli(number_format((float) ($inBatches[$key] ?? 0), 3, '.', '')) !== $onHand)) {
                    $out[] = $this->skip($p, $wh->use_bins ? 'Batches in a store with bins: ask SyncLytics' : 'Its batches do not add up to the stock on hand: count it first, then run again', $wh);

                    continue;
                }
                if ($missing || $run->overwrite) {
                    if ($priced === null || $priced <= 0) {
                        if (! $reported) {
                            $out[] = $this->skip($p, $priced === null ? "No selling price on {$list->name}" : 'The price is too small to give a cost');
                            $reported = true;
                        }

                        continue;
                    }
                    $kind = $missing ? 'estimate' : 'overwrite';
                    $cost = $priced;
                } else {
                    $cost = (int) round((float) $avg);
                    $kind = 'top_up';
                    $short = Qty::divRound($cost * $onHand, 1000) - ($held[$key] ?? 0);
                    $topUp = isset($noCostIn[$key]) && $short >= self::TOP_UP_FROM_MINOR;
                    if (! $topUp && $soldMilli === 0) {
                        $costed++;

                        continue;
                    }
                    if (! $topUp) {
                        $onHand = 0; // only past sales to catch up
                    }
                }
                $before = $onHand > 0 ? ($held[$key] ?? 0) : 0;
                $out[] = ['product_id' => $p->id, 'warehouse_id' => $l->warehouse_id, 'branch_id' => $wh->branch_id, 'kind' => $kind, 'skip_reason' => null,
                    'on_hand' => Qty::format($onHand), 'sold_qty' => Qty::format($soldMilli), 'price_minor' => $used, 'value_bp' => $bp, 'old_avg_minor' => $avg, 'new_cost_minor' => $cost,
                    'value_before_minor' => $before, 'stock_value_minor' => Qty::divRound($cost * $onHand, 1000) - $before, 'catch_up_minor' => Qty::divRound($cost * $soldMilli, 1000)];
            }
        }
        $run->already_costed_count = $costed;

        return $out;
    }

    /** @return array<string, mixed> */
    private function skip(Product $p, string $reason, ?Warehouse $wh = null): array
    {
        return ['product_id' => $p->id, 'warehouse_id' => $wh?->id, 'branch_id' => $wh?->branch_id, 'kind' => 'skipped', 'skip_reason' => $reason];
    }

    /** The factor for a product: its category's, its brand's, or the run's. */
    private function factorFor(StockCostRun $run, Product $p): int
    {
        foreach ($run->overrides ?? [] as $o) {
            if (($o['kind'] === 'category' && $o['id'] === $p->category_id) || ($o['kind'] === 'brand' && $o['id'] === $p->brand_id)) {
                return (int) $o['value_bp'];
            }
        }

        return $run->value_bp;
    }

    /**
     * Single-unit prices on the list today, as [product id => [unit id => amount]]; among rows for the same unit the
     * order is the price resolver's, without asking it once per product.
     */
    private function prices(StockCostRun $run): array
    {
        $now = now();
        $out = [];
        foreach (DB::table('prices')->where('price_list_id', $run->price_list_id)->where('min_qty', '<=', 1)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderByDesc('min_qty')->orderByRaw('starts_at IS NULL')->orderByDesc('starts_at')->get(['product_id', 'product_uom_id', 'amount_minor']) as $r) {
            $out[$r->product_id][$r->product_uom_id] ??= (int) $r->amount_minor;
        }

        return $out;
    }

    /** The price of one base unit: the base unit's own, or the smallest unit that has one, divided down. */
    private function storedPrice(Product $p, array $prices): ?string
    {
        foreach ($p->units->sortBy(fn ($u) => (float) $u->factor) as $u) {
            if (isset($prices[$u->id]) && $prices[$u->id] > 0) {
                return bcdiv((string) $prices[$u->id], (string) $u->factor, 8);
            }
        }

        return null;
    }

    /** What the books hold per store and product ("warehouse|product" => minor): movements at cost plus freight spread onto stock. */
    private function booksHold(string $tenantId): array
    {
        $out = [];
        foreach (DB::table('stock_movements')->where('tenant_id', $tenantId)->whereNotIn('type', ['putaway', 'batch_assign'])->groupBy('warehouse_id', 'product_id')
            ->selectRaw('warehouse_id, product_id, SUM(ROUND(qty_entered * COALESCE(unit_cost_minor, 0)))::bigint AS v')->get() as $r) {
            $out["{$r->warehouse_id}|{$r->product_id}"] = (int) $r->v;
        }
        foreach (DB::table('landed_cost_lines as l')->join('landed_costs as c', 'c.id', '=', 'l.landed_cost_id')->where('c.tenant_id', $tenantId)->groupBy('l.warehouse_id', 'l.product_id')
            ->selectRaw('l.warehouse_id, l.product_id, SUM(l.to_stock_minor)::bigint AS v')->get() as $r) {
            $k = "{$r->warehouse_id}|{$r->product_id}";
            $out[$k] = ($out[$k] ?? 0) + (int) $r->v;
        }

        return $out;
    }

    /** Store and product pairs where stock came in with no cost. */
    private function receivedAtNoCost(string $tenantId): array
    {
        $out = [];
        foreach (DB::table('stock_movements')->where('tenant_id', $tenantId)->where('qty_entered', '>', 0)->where(fn ($q) => $q->whereNull('unit_cost_minor')->orWhere('unit_cost_minor', 0))
            ->whereNotIn('type', ['putaway', 'batch_assign', 'unit_conversion', ...self::COST_TYPES])->distinct()->get(['warehouse_id', 'product_id']) as $r) {
            $out["{$r->warehouse_id}|{$r->product_id}"] = true;
        }

        return $out;
    }

    /** Sold at no cost and not yet caught up by a run ("warehouse|product" => milli base units). */
    private function soldAtNoCost(string $tenantId): array
    {
        $out = [];
        foreach (DB::table('stock_movements')->where('tenant_id', $tenantId)->whereIn('type', ['sale', 'sale_return'])->where(fn ($q) => $q->whereNull('unit_cost_minor')->orWhere('unit_cost_minor', 0))
            ->groupBy('warehouse_id', 'product_id')->selectRaw('warehouse_id, product_id, -SUM(qty_base) AS q')->get() as $r) {
            $out["{$r->warehouse_id}|{$r->product_id}"] = Qty::toMilli(number_format((float) $r->q, 3, '.', ''));
        }
        foreach (DB::table('stock_cost_run_lines as l')->join('stock_cost_runs as r', 'r.id', '=', 'l.stock_cost_run_id')->where('l.tenant_id', $tenantId)->where('r.status', 'posted')->whereNull('r.reverses_id')
            ->whereNotNull('l.warehouse_id')->groupBy('l.warehouse_id', 'l.product_id')->selectRaw('l.warehouse_id, l.product_id, SUM(l.sold_qty) AS q')->get() as $r) {
            $k = "{$r->warehouse_id}|{$r->product_id}";
            if (isset($out[$k])) {
                $out[$k] -= Qty::toMilli(number_format((float) $r->q, 3, '.', ''));
            }
        }

        return array_filter($out, fn (int $q) => $q > 0);
    }

    /** @param list<array<string, mixed>> $rows */
    private function store(StockCostRun $run, array $rows): void
    {
        $costed = $run->already_costed_count;
        $run->lines()->delete();
        $now = now();
        foreach (array_chunk($rows, 500) as $part) {
            DB::table('stock_cost_run_lines')->insert(array_map(fn ($r) => $r + ['id' => (string) Str::uuid7(), 'tenant_id' => $run->tenant_id, 'stock_cost_run_id' => $run->id,
                'on_hand' => $r['on_hand'] ?? '0', 'sold_qty' => $r['sold_qty'] ?? '0', 'price_minor' => $r['price_minor'] ?? null, 'value_bp' => $r['value_bp'] ?? null,
                'old_avg_minor' => $r['old_avg_minor'] ?? null, 'new_cost_minor' => $r['new_cost_minor'] ?? null, 'value_before_minor' => $r['value_before_minor'] ?? 0,
                'stock_value_minor' => $r['stock_value_minor'] ?? 0, 'catch_up_minor' => $r['catch_up_minor'] ?? 0, 'created_at' => $now, 'updated_at' => $now], $part));
        }
        $done = collect($rows)->where('kind', '<>', 'skipped');
        $run->forceFill(['stock_value_minor' => (int) $done->sum('stock_value_minor'), 'catch_up_minor' => (int) $done->sum('catch_up_minor'),
            'lines_count' => $done->count(), 'skipped_count' => count($rows) - $done->count(), 'already_costed_count' => $costed ?? 0])->save();
    }

    private static function valueBp(string $method, mixed $value): int
    {
        $n = is_numeric($value) ? (float) $value : 0.0;
        if ($method === 'divide') {
            if ($n <= 1 || $n > 20) {
                throw new DomainException('The factor must be more than 1, e.g. 1.4 for a selling price 40% above cost.');
            }

            return (int) round($n * 10000);
        }
        if ($n <= 0 || $n >= 100) {
            throw new DomainException('The profit percentage must be between 0 and 100, e.g. 40.');
        }

        return (int) round($n * 100);
    }

    private function mustBeOpen(StockCostRun $run): void
    {
        if (! $run->isOpen()) {
            throw new DomainException('This run is finished.');
        }
    }

    private function mustHaveLines(StockCostRun $run): void
    {
        if (! $run->lines()->where('kind', '<>', 'skipped')->exists()) {
            throw new DomainException('Nothing in this run can be costed: see the skipped products.');
        }
    }

    private function require(Actor $actor, string $permission): void
    {
        if (config('erp.node.role') === 'branch') {
            throw new DomainException('Cost runs are done at head office.', 'hq_only');
        }
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }

    private function requireBranch(Actor $actor, string $permission, ?string $branchId): void
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        if (! $actor->user || ! $branch || ! $this->authz->allows($actor->user, $permission, $branch)) {
            throw new AccessDenied;
        }
    }
}
