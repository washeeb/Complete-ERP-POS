<?php

namespace App\Console\Commands;

use App\Domain\Catalog\DuplicateProducts;
use App\Models\Tenant;
use Illuminate\Console\Command;

/** php artisan erp:merge-duplicate-products [--apply] — products imported more than once: show, then fold into the first copies. */
class MergeDuplicateProductsCommand extends Command
{
    protected $signature = 'erp:merge-duplicate-products {--apply : do it (without this, only show what would happen)}';

    protected $description = 'Fold products created twice by importing the same file again into the first copies (barcodes kept)';

    public function handle(DuplicateProducts $duplicates): int
    {
        foreach (Tenant::where('is_practice', false)->get() as $tenant) {
            $plan = $duplicates->plan($tenant->id);
            $copies = array_sum(array_map(fn ($g) => count($g['copies']), $plan));
            $this->info("{$tenant->name}: {$copies} duplicate product(s) of ".count($plan).' product(s).');
            foreach (array_slice($plan, 0, 15) as $g) {
                $this->line("  keep {$g['keep']->sku} {$g['keep']->name}  ←  ".implode(', ', array_map(fn ($c) => $c->sku, $g['copies'])));
            }
            if (count($plan) > 15) {
                $this->line('  … and '.(count($plan) - 15).' more.');
            }
            if (! $plan) {
                continue;
            }
            if (! $this->option('apply')) {
                $this->comment('  Nothing changed. Run again with --apply to do it (take a backup first: System → Backups).');

                continue;
            }
            $t = $duplicates->apply($tenant->id, $plan);
            $this->info("  Done: {$t['removed']} removed, {$t['switched_off']} switched off (in use), {$t['barcodes_moved']} barcode(s) moved to the kept products.");
        }

        return self::SUCCESS;
    }
}
