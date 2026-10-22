<?php

namespace App\Domain\Messaging;

use App\Filament\Resources\Sales\SaleResource;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Mobile money and card payment alerts (owner request 2026-10-22): each payment at a till, and a daily summary, to the
 * people with "receive payment alerts" for that branch — in the back office (the bell), and by e-mail, SMS or WhatsApp
 * when switched on (System → Settings → Payment alerts). Messages are handed to the relay, so a till's sync never waits
 * for a provider. An alert that cannot be raised never stops the sale.
 */
final class PaymentAlerts
{
    public const PERMISSION = 'payments.alerts';

    private const NETWORKS = ['mtn' => 'MTN MoMo', 'telecel' => 'Telecel Cash', 'at' => 'AT Money'];

    public function __construct(private readonly Settings $settings, private readonly Authorizer $authz, private readonly Messenger $messenger) {}

    /** The methods alerted on, by setting. */
    public function methods(string $tenantId): array
    {
        return match ($this->settings->get('alerts.payment_methods', $tenantId)) {
            'momo' => ['momo'], 'card' => ['card'], default => ['momo', 'card'],
        };
    }

    public function forSale(string $saleId): int
    {
        try {
            $sale = Sale::withoutGlobalScopes()->find($saleId);
            if (! $sale || ! in_array($this->settings->get('alerts.payments', $sale->tenant_id), ['each', 'each_summary'], true) || Tenant::isPractice($sale->tenant_id)) {
                return 0;
            }
            $min = (int) $this->settings->get('alerts.payment_min_minor', $sale->tenant_id);
            $payments = SalePayment::where('sale_id', $sale->id)->whereIn('method', $this->methods($sale->tenant_id))->where('amount_minor', '>=', max(1, $min))->get();
            if ($payments->isEmpty()) {
                return 0;
            }
            $people = $this->recipients($sale->tenant_id, $sale->branch_id);
            $branch = Branch::withoutGlobalScopes()->find($sale->branch_id);
            $till = Till::withoutGlobalScopes()->find($sale->till_id);
            $company = Tenant::find($sale->tenant_id)?->name ?? '';
            $tz = $branch?->timezone ?: (Tenant::find($sale->tenant_id)?->timezone ?: 'UTC');
            foreach ($payments as $p) {
                $vars = ['company' => $company, 'method' => self::label($p), 'amount' => Money::display((int) $p->amount_minor, $sale->currency_code),
                    'receipt_no' => $sale->receipt_no, 'branch' => $branch?->name ?? '', 'till' => $till?->name ?? '', 'cashier' => $sale->cashier_name ?? '',
                    'reference' => $p->reference ?: '—', 'time' => Carbon::parse($sale->occurred_at)->setTimezone($tz)->format('j M H:i')];
                $this->toPeople($sale->tenant_id, $people, 'payment_alert', $vars, "{$vars['method']} {$vars['amount']} received",
                    "Receipt {$vars['receipt_no']}, {$vars['branch']} ({$vars['till']}), {$vars['time']}. Ref {$vars['reference']}.", SaleResource::getUrl('view', ['record' => $sale->id]), $sale->receipt_no);
            }

            return $payments->count();
        } catch (Throwable $e) {
            report($e); // an alert is never worth losing a sale over

            return 0;
        }
    }

    /**
     * The day's mobile money and card payments per branch, sent once a day after the set time (run every few minutes).
     *
     * @return int people told
     */
    public function dailySummaries(?Carbon $now = null): int
    {
        $sent = 0;
        foreach (Tenant::query()->get() as $tenant) {
            if (! in_array($this->settings->get('alerts.payments', $tenant->id), ['each_summary', 'summary'], true) || Tenant::isPractice($tenant->id)) {
                continue;
            }
            $local = ($now ?? now())->copy()->setTimezone($tenant->timezone ?: 'UTC');
            $at = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $this->settings->get('alerts.summary_at', $tenant->id)) ? $this->settings->get('alerts.summary_at', $tenant->id) : '21:00';
            $key = "payment-summary:{$tenant->id}:{$local->toDateString()}";
            if ($local->format('H:i') < $at || Cache::has($key)) {
                continue;
            }
            Cache::put($key, true, now()->addDays(2));
            $sent += $this->summary($tenant, $local->copy()->startOfDay(), $local->copy()->endOfDay());
        }

        return $sent;
    }

    public function summary(Tenant $tenant, Carbon $from, Carbon $to): int
    {
        $rows = SalePayment::query()->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.tenant_id', $tenant->id)->where('sales.status', 'posted')->whereIn('sale_payments.method', $this->methods($tenant->id))
            ->whereBetween('sales.occurred_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->selectRaw('sales.branch_id, sale_payments.method, sale_payments.provider, COUNT(*) AS n, SUM(sale_payments.amount_minor) AS total')
            ->groupBy('sales.branch_id', 'sale_payments.method', 'sale_payments.provider')->get();
        $told = 0;
        foreach (Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get() as $branch) {
            $mine = $rows->where('branch_id', $branch->id);
            $people = $this->recipients($tenant->id, $branch->id);
            if ($people->isEmpty() || $mine->isEmpty()) {
                continue;
            }
            $m = fn (int $v) => Money::display($v, $tenant->currency_code);
            $lines = $mine->map(fn ($r) => self::label((object) ['method' => $r->method, 'provider' => $r->provider]).": {$m((int) $r->total)} ({$r->n})")->implode("\n");
            $vars = ['company' => $tenant->name, 'date' => $from->format('j M Y').' · '.$branch->name, 'total' => $m((int) $mine->sum('total')),
                'count' => (string) $mine->sum('n'), 'lines' => $lines];
            $this->toPeople($tenant->id, $people, 'payment_summary', $vars, "Mobile money and card, {$vars['date']}: {$vars['total']}", $lines, null, 'SUM-'.$from->format('Ymd'));
            $told += $people->count();
        }

        return $told;
    }

    /** @return Collection<int, User> active people allowed alerts for the branch */
    private function recipients(string $tenantId, ?string $branchId): Collection
    {
        $branch = $branchId ? Branch::withoutGlobalScopes()->find($branchId) : null;

        return User::where('tenant_id', $tenantId)->where('is_active', true)->get()
            ->filter(fn (User $u) => $branch ? $this->authz->allows($u, self::PERMISSION, $branch) : $this->authz->allowsAnywhere($u, self::PERMISSION))->values();
    }

    private function toPeople(string $tenantId, Collection $people, string $template, array $vars, string $title, string $body, ?string $url, string $reference): void
    {
        if ($people->isEmpty()) {
            return;
        }
        $n = Notification::make()->title($title)->body($body)->success();
        if ($url) {
            $n->actions([Action::make('open')->label('Open the sale')->url($url)->markAsRead()]);
        }
        $n->sendToDatabase($people);
        foreach (['email' => 'alerts.by_email', 'sms' => 'alerts.by_sms', 'whatsapp' => 'alerts.by_whatsapp'] as $channel => $setting) {
            $t = $this->settings->get($setting, $tenantId) ? Templates::find($tenantId, $template, $channel) : null;
            if (! $t?->is_active) {
                continue;
            }
            foreach ($people as $u) {
                $this->messenger->later($tenantId, $channel, $channel === 'email' ? $u->email : $u->phone, $t, $vars, $reference, 'Payment alerts');
            }
        }
    }

    private static function label(object $p): string
    {
        return $p->method === 'momo' ? (self::NETWORKS[$p->provider] ?? 'Mobile money') : 'Card';
    }
}
