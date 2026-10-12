<?php

namespace App\Domain\Org;

use App\Domain\Finance\ChartTemplate;
use App\Domain\Hr\HrBaseline;
use App\Domain\Messaging\Templates;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\Outbox;
use App\Models\PriceList;
use App\Models\ShiftTemplate;
use App\Models\TaxGroup;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Uom;
use Illuminate\Support\Facades\DB;

/**
 * Starter reference data for a company. Runs at install and on every deploy (seeder), and only
 * ever fills an EMPTY collection: once a company has any units, tax rates or price lists,
 * those are theirs and are never touched again.
 */
final class TenantBaseline
{
    public function __construct(private readonly Outbox $outbox) {}

    public const UOMS = [
        ['PC', 'Piece', 0], ['PK', 'Pack', 0], ['CTN', 'Carton', 0], ['DZ', 'Dozen', 0], ['BAG', 'Bag', 0],
        ['KG', 'Kilogram', 3], ['G', 'Gram', 0], ['L', 'Litre', 3], ['ML', 'Millilitre', 0], ['M', 'Metre', 2],
    ];

    /**
     * Ghana standard-rated supplies as configured at build time. NOT VERIFIED: the company's
     * accountant must confirm rates and method under Settings → Tax before go-live (spec §7).
     */
    public const GH_RATES = [['VAT', 'VAT', 1500, 1], ['NHIL', 'NHIL', 250, 2], ['GETFUND', 'GETFund levy', 250, 3]];

    public function apply(Tenant $tenant): void
    {
        $tenant = $tenant->fresh(); // column defaults (country, currency) are only known after a reload
        DB::transaction(function () use ($tenant) {
            $node = (string) config('erp.node.id');
            // Created as named system data; published so branch servers receive it too.
            $actor = Actor::system($tenant->id, 'Starter data', 'system');
            $publish = fn ($model) => $this->outbox->publish($actor, $model->fresh(), AuditLog::typeOf($model).'.created');
            $base = fn () => ['tenant_id' => $tenant->id, 'origin_node' => $node];

            if (! Uom::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
                foreach (self::UOMS as [$code, $name, $dec]) {
                    $publish(tap((new Uom(['code' => $code, 'name' => $name, 'decimals' => $dec]))->forceFill($base()))->save());
                }
            }

            if (! TaxRate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()
                && ! TaxGroup::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
                $rates = [];
                if ($tenant->country_code === 'GH') {
                    foreach (self::GH_RATES as [$code, $name, $bp, $sort]) {
                        $r = (new TaxRate(['code' => $code, 'name' => $name, 'rate_bp' => $bp, 'sort' => $sort]))->forceFill($base());
                        $r->save();
                        $publish($r);
                        $rates[] = $r->id;
                    }
                }
                $std = (new TaxGroup(['code' => 'STD', 'name' => 'Standard rated']))->forceFill($base());
                $std->save();
                $std->rates()->sync($rates);
                $publish($std);
                $publish(tap((new TaxGroup(['code' => 'EXEMPT', 'name' => 'Exempt / zero rated']))->forceFill($base()))->save());
            }

            if (! PriceList::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
                (new PriceList(['code' => 'RETAIL', 'name' => 'Retail', 'currency_code' => $tenant->currency_code,
                    'prices_include_tax' => true, 'is_default' => true, 'pos_selectable' => true]))->forceFill($base())->save();
                $publish(PriceList::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', 'RETAIL')->first());
                (new PriceList(['code' => 'WHOLESALE', 'name' => 'Wholesale', 'currency_code' => $tenant->currency_code,
                    'prices_include_tax' => true, 'is_default' => false, 'pos_selectable' => true]))->forceFill($base())->save();
                $publish(PriceList::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', 'WHOLESALE')->first());
            }

            // The two default shifts (cash control): each till gets a vault per shift when cash control is switched on.
            foreach ([['Morning', '07:00', '14:00', 1], ['Afternoon', '14:00', '21:00', 2]] as [$name, $from, $to, $sort]) {
                if (! ShiftTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', $name)->exists()) {
                    (new ShiftTemplate(['name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'sort' => $sort, 'is_active' => true]))->forceFill($base())->save();
                    $publish(ShiftTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', $name)->first());
                }
            }

            // Finance is kept at head office only.
            if (config('erp.node.role') !== 'branch') {
                ChartTemplate::ensure($tenant);
                HrBaseline::ensure($tenant);
                Templates::seed($tenant); // message wording; branch servers receive it by sync
            }
        });
    }
}
