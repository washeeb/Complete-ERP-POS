<?php

namespace App\Domain\Office;

use App\Domain\Inventory\ExpiryService;
use App\Filament\Pages\ExpiryReturns;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;

/**
 * Expiry & returns in the new back office (part 13 of the rewrite), as the current page: expired and near-expiry
 * batches in one list (by period, store, supplier, controlled drugs, what the supplier still takes back), then tick
 * them and Dispose, Return to supplier or Mark down; the disposal register to print. Through ExpiryService and
 * MarkdownService (ExpiryController); disposals and returns wait for a second person as before.
 */
final class ExpiryScreen
{
    public const PER_PAGE = [25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return ExpiryReturns::canAccess();
    }

    private static function pairs(array $a): array
    {
        return array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);
    }

    private function alertDays(): int
    {
        return (int) app(Settings::class)->get('stock.expiry_alert_days', $this->user->tenant_id);
    }

    /** @return array<string, string> the stores the person handles expiry in */
    public static function warehouses(): array
    {
        return Fields::warehouses('stock.expiry');
    }

    public function options(): array
    {
        $evidence = (string) app(Settings::class)->get('stock.disposal_evidence', $this->user->tenant_id);
        $warehouses = self::warehouses();

        return ['statuses' => self::pairs(['expired' => 'Expired', '30' => 'Expired or expiring within 30 days', 'soon' => 'Expired or expiring within '.$this->alertDays().' days',
            'all' => 'Every batch with an expiry date']), 'warehouses' => self::pairs($warehouses),
            'suppliers' => self::pairs(Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            'settlements' => self::pairs(SupplierReturn::SETTLEMENTS), 'reasons' => self::pairs(SupplierReturn::REASONS),
            'methods' => self::pairs(ExpiryService::DISPOSAL_METHODS), 'evidence' => $evidence, 'today' => now()->toDateString(),
            'month_start' => now()->startOfMonth()->toDateString(), 'can_mark_down' => Erp::canAnywhere('grocery.markdowns')];
    }

    /** @param  array<string, mixed>  $p  status (default soon), warehouse, supplier, controlled, returnable, q */
    public function rows(array $p): array
    {
        $warehouses = array_keys(self::warehouses());
        $wh = (string) ($p['warehouse'] ?? '');
        $status = in_array($p['status'] ?? '', ['expired', '30', 'all'], true) ? $p['status'] : 'soon';
        $rows = app(ExpiryService::class)->batches($this->user->tenant_id, [
            'warehouse_ids' => $wh !== '' && in_array($wh, $warehouses, true) ? [$wh] : ($warehouses ?: ['00000000-0000-0000-0000-000000000000']),
            'expired' => $status === 'expired',
            'within_days' => match ($status) {
                'soon' => $this->alertDays(), '30' => 30, default => null
            },
            'controlled' => ($p['controlled'] ?? '') === '1',
            'supplier_id' => ($p['supplier'] ?? '') !== '' ? (string) $p['supplier'] : null,
            'returnable' => ($p['returnable'] ?? '') === '1',
        ]);
        $term = mb_strtolower(trim((string) ($p['q'] ?? '')));

        return array_values(array_filter($rows, fn ($r) => $term === '' || str_contains(mb_strtolower("{$r['name']} {$r['sku']} {$r['batch_no']}"), $term)));
    }

    /** @param  array<string, mixed>  $p  the filters, page, per */
    public function list(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 50;
        $page = max(1, (int) ($p['page'] ?? 1));
        $rows = $this->rows($p);
        $currency = FinanceUi::currency();

        return ['total' => count($rows), 'page' => $page, 'per' => $per, 'currency' => $currency, 'exponent' => Money::exponent($currency),
            'value' => array_sum(array_column($rows, 'value_minor')),
            'rows' => array_map(fn ($r) => ['id' => $r['key'], 'warehouse_id' => $r['warehouse_id'], 'warehouse' => $r['warehouse'], 'product' => $r['name'], 'sku' => $r['sku'],
                'controlled' => $r['controlled'], 'batch_no' => $r['batch_no'], 'expires_on' => $r['expires_on'], 'days_left' => $r['days_left'],
                'on_hand' => Fields::qty($r['qty'], $r['unit']), 'value' => $r['value_minor'], 'supplier_id' => $r['supplier_id'], 'supplier' => $r['supplier'],
                'returnable' => $r['returnable']], array_slice($rows, ($page - 1) * $per, $per))];
    }

    /** Where a prepared return opens. */
    public static function returnUrl(SupplierReturn $r): string
    {
        return '/office/supplier-returns/'.$r->id;
    }
}
