<?php

namespace App\Console\Commands;

use App\Domain\Customers\CustomerService;
use App\Domain\Pricing\PromotionService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Support\Caps;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Sample customers (fictitious) for demos and training — never real people's data. */
class DemoSalesCommand extends Command
{
    protected $signature = 'erp:demo-sales {--as= : email of the person loading the sample data}';

    protected $description = 'Load sample customers with credit accounts (for demos and training only)';

    private const CUSTOMERS = [
        // name, type, phone, price list, credit limit (minor), terms
        ['Asante Provisions (sample)', 'business', '0300000001', 'WHOLESALE', 2_000_000, 30],
        ['Mama Esi Chop Bar (sample)', 'business', '0300000002', 'WHOLESALE', 300_000, 14],
        ['Kwame Mensah (sample)', 'individual', '0300000003', null, null, 0],
    ];

    public function handle(CustomerService $customers): int
    {
        $user = User::where('email', strtolower((string) $this->option('as')))->first();
        if (! $user) {
            $this->error('Pass --as=<email> of the person loading the sample data.');

            return self::FAILURE;
        }
        app(TenantContext::class)->set($user->tenant_id);
        $actor = new Actor($user->id, $user->name, 'cli', $user->tenant_id, user: $user);
        $hq = Branch::where('is_hq', true)->value('id');
        try {
            foreach (self::CUSTOMERS as [$name, $type, $phone, $list, $limit, $terms]) {
                if (Customer::where('name', Caps::upper($name))->exists()) {
                    continue;
                }
                $customers->save($actor, new Customer, ['name' => $name, 'type' => $type, 'phone' => $phone, 'home_branch_id' => $hq,
                    'price_list_id' => $list ? PriceList::where('code', $list)->value('id') : null,
                    'credit_limit_minor' => $limit, 'payment_terms_days' => $terms, 'tags' => ['sample']], 'sample data');
            }
            // Sample promotions (one of each kind the till shows).
            $id = fn (string $sku) => Product::where('sku', $sku)->value('id');
            $samples = [
                ['name' => 'Indomie 5 for GHS 22', 'type' => 'bundle', 'product_ids' => [$id('NOODLE-70')], 'bundle_qty' => 5, 'value_minor' => 2200],
                ['name' => 'Malta 3 for 2', 'type' => 'buy_x_get_y', 'product_ids' => [$id('MALT-330')], 'buy_qty' => 2, 'get_qty' => 1, 'value_bp' => 10000],
                ['name' => 'Bread happy hour 20%', 'type' => 'percent', 'product_ids' => [$id('BREAD-SLC')], 'value_bp' => 2000, 'time_from' => '17:00', 'time_to' => '19:00'],
                ['name' => 'Spend GHS 200, save 5%', 'type' => 'threshold', 'min_spend_minor' => 20000, 'value_bp' => 500],
                ['name' => 'WELCOME10 coupon', 'type' => 'coupon', 'coupon_code' => 'WELCOME10', 'value_minor' => 1000, 'min_spend_minor' => 5000],
            ];
            foreach ($samples as $s) {
                if (! Promotion::where('name', $s['name'])->exists() && ! in_array(null, $s['product_ids'] ?? [], true)) {
                    app(PromotionService::class)->save($actor, new Promotion, $s + ['is_active' => true]);
                }
            }
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Sample customers and promotions are in place.');

        return self::SUCCESS;
    }
}
