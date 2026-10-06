<?php

namespace App\Console\Commands;

use App\Domain\Customers\CustomerService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\User;
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
                if (Customer::where('name', $name)->exists()) {
                    continue;
                }
                $customers->save($actor, new Customer, ['name' => $name, 'type' => $type, 'phone' => $phone, 'home_branch_id' => $hq,
                    'price_list_id' => $list ? PriceList::where('code', $list)->value('id') : null,
                    'credit_limit_minor' => $limit, 'payment_terms_days' => $terms, 'tags' => ['sample']], 'sample data');
            }
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Sample customers are in place.');

        return self::SUCCESS;
    }
}
