<?php

namespace App\Domain\Payables;

use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Ghana withholding tax on payments to suppliers (Income Tax Act 2015, Act 896, s. 116): the payer keeps back a share
 * of each payment for goods, services or works and pays it to GRA by the 15th of the next month, giving the supplier a
 * credit certificate. Rates and the threshold are settings (default goods 3 %, services 7.5 %, works 5 %; nothing
 * below GHS 2,000), since GRA changes them; the accountant confirms them.
 *
 * The tax is on the amount before VAT: a payment against bills counts only each bill's share before tax. A supplier
 * with a withholding tax exemption certificate in date is exempt.
 */
final class WithholdingTax
{
    public function __construct(private readonly Settings $settings) {}

    public function enabled(string $tenantId): bool
    {
        return (bool) $this->settings->get('payables.wht', $tenantId);
    }

    public function rateBp(Supplier $s): int
    {
        return (int) $this->settings->get('payables.wht_'.($s->supply_kind ?: 'goods').'_bp', $s->tenant_id);
    }

    public function exempt(Supplier $s): bool
    {
        return SupplierDocument::where('supplier_id', $s->id)->where('kind', 'wht_exemption')
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))->exists();
    }

    /**
     * What to keep back from a payment that settles $plan (from SupplierLedger::plan) out of $grossMinor.
     *
     * @param  list<array{to_type: string, to_id: string, amount_minor: int}>  $plan
     * @return array{wht: int, base: int, rate_bp: int, why: ?string} why: the reason nothing is kept back
     */
    public function compute(Supplier $s, int $grossMinor, array $plan): array
    {
        $none = fn (string $why) => ['wht' => 0, 'base' => 0, 'rate_bp' => 0, 'why' => $why];
        if (! $this->enabled($s->tenant_id)) {
            return $none('Withholding tax is off (Settings → Withholding tax).');
        }
        if ($this->exempt($s)) {
            return $none("{$s->name} has a withholding tax exemption certificate in date.");
        }
        // The base: each bill's share before VAT; anything else (opening balance, on account) as it is.
        $bills = DB::table('supplier_bills')->whereIn('id', collect($plan)->where('to_type', 'supplier_bill')->pluck('to_id'))->get(['id', 'net_minor', 'total_minor'])->keyBy('id');
        $base = 0;
        $placed = 0;
        foreach ($plan as $p) {
            $b = $p['to_type'] === 'supplier_bill' ? $bills->get($p['to_id']) : null;
            $base += $b && (int) $b->total_minor > 0 ? Qty::divRound((int) $p['amount_minor'] * (int) $b->net_minor, (int) $b->total_minor) : (int) $p['amount_minor'];
            $placed += (int) $p['amount_minor'];
        }
        $base += max(0, $grossMinor - $placed);
        $threshold = (int) $this->settings->get('payables.wht_threshold_minor', $s->tenant_id);
        if ($base < $threshold) {
            return $none('Below the withholding tax threshold.');
        }
        $rate = $this->rateBp($s);

        return ['wht' => Qty::divRound($base * $rate, 10000), 'base' => $base, 'rate_bp' => $rate, 'why' => null];
    }
}
