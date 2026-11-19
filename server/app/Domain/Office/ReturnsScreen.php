<?php

namespace App\Domain\Office;

use App\Domain\Pos\ReturnService;
use App\Filament\Pages\ReturnsReport;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Returns in the new back office (release 7 of the rewrite): till returns, newest first, with the current page's
 * columns (who took it, who approved it, the reason, the items, where they went, the refund and how it was paid back),
 * its filters (dates, who took it, reason, product, approval), the 30-day summary that names one person doing most of
 * them, and the return slip to sign. Built on the till sales list's filters (SalesScreen, kind = return).
 */
final class ReturnsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    /** Named when one person has more than this share of the returns, and at least this many of them (as the current page). */
    private const SHARE = 0.5;

    private const AT_LEAST = 5;

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return ReturnsReport::canAccess();
    }

    private function sales(): SalesScreen
    {
        return new SalesScreen($this->user);
    }

    /** @param  array<string, mixed>  $p  q, period, from, to, teller, reasons[], products[], approval, sort, dir, page, per */
    public function filtered(array $p): Builder
    {
        $q = $this->sales()->filtered(['kind' => 'return'] + array_intersect_key($p, array_flip(['q', 'period', 'from', 'to', 'teller', 'branch'])));
        $reasons = array_values(array_intersect((array) ($p['reasons'] ?? []), array_keys(ReturnService::REASONS)));
        if ($reasons) {
            $q->whereIn('return_reason_code', $reasons);
        }
        $products = array_values(array_filter((array) ($p['products'] ?? []), fn ($v) => is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v)));
        if ($products) {
            $q->whereIn('sales.id', DB::table('sale_lines')->whereIn('product_id', $products)->select('sale_id'));
        }
        if (($p['approval'] ?? '') === 'approved') {
            $q->whereNotNull('discount_approved_by_name');
        } elseif (($p['approval'] ?? '') === 'none') {
            $q->whereNull('discount_approved_by_name');
        }

        return $q;
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->sales()->order($p);

        return $this->filtered($p)->with(['branch:id,code,name', 'payments:id,sale_id,method', 'lines'])->orderBy($sort, $dir)->orderBy('id', $dir);
    }

    private static function qty(mixed $v): string
    {
        return rtrim(rtrim(ltrim((string) $v, '-'), '0'), '.');
    }

    public function rowOf(Sale $r): array
    {
        return [
            'id' => $r->id, 'receipt_no' => $r->receipt_no, 'occurred_at' => $r->occurred_at?->toIso8601String(), 'branch' => $r->branch?->code,
            'by' => $r->cashier_name, 'approved_by' => $r->discount_approved_by_name,
            'reason' => (ReturnService::REASONS[$r->return_reason_code] ?? null) ?: $r->return_reason_code, 'details' => $r->return_reason,
            'items' => $r->lines->map(fn ($l) => self::qty($l->qty).' '.$l->uom_code.' '.$l->name)->implode(', '),
            'to' => $r->lines->contains('returned_to', 'quarantine') ? 'Quarantine' : 'Shelf',
            'refund' => -(int) $r->total_minor, 'currency' => $r->currency_code,
            'paid_by' => $r->payments->pluck('method')->unique()->map(fn ($m) => SalePayment::METHODS[$m] ?? $m)->implode(' + '),
            'open' => "/office/sales/{$r->id}", 'slip' => "/office-api/returns/{$r->id}/slip",
        ];
    }

    /** "12 return(s) in the last 30 days, worth GHS 340.00. Worth a look: Ama did 8 of them." */
    public function summary(): string
    {
        $last = $this->filtered(['period' => '30']);
        $n = (clone $last)->count();
        if ($n === 0) {
            return 'No returns in the last 30 days.';
        }
        $top = (clone $last)->toBase()->reorder()->select('cashier_name', DB::raw('COUNT(*) AS n'))->groupBy('cashier_name')->orderByDesc('n')->first();
        $note = $top->n >= self::AT_LEAST && $top->n / $n > self::SHARE ? " Worth a look: {$top->cashier_name} did {$top->n} of them." : '';
        $currency = $this->user->tenant?->currency_code ?? 'GHS';

        return "{$n} return(s) in the last 30 days, worth ".Money::display(-(int) (clone $last)->sum('total_minor'), $currency).'.'.$note;
    }

    public function list(array $p): array
    {
        [$sort, $dir] = $this->sales()->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_minor), 0) AS total')->first();
        $currency = $this->user->tenant?->currency_code ?? 'GHS';

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'total' => (int) $t->n, 'refunded' => -(int) $t->total,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (Sale $r) => $this->rowOf($r))->all(),
        ];
    }

    public function options(): array
    {
        $o = $this->sales()->options();
        $ids = $this->sales()->filtered(['kind' => 'return'])->select('sales.id');

        return [
            'tellers' => $o['tellers'], 'branches' => $o['branches'], 'per' => self::PER_PAGE, 'summary' => $this->summary(),
            'reasons' => PurchaseOrdersScreen::choices(ReturnService::REASONS),
            'products' => DB::table('sale_lines')->whereIn('sale_id', $ids)->whereNotNull('product_id')->groupBy('product_id')
                ->selectRaw('product_id, MAX(name) AS name')->orderBy('name')->limit(500)->get()->map(fn ($r) => ['id' => $r->product_id, 'label' => $r->name])->all(),
        ];
    }
}
