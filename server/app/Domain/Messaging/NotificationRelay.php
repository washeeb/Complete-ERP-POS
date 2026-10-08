<?php

namespace App\Domain\Messaging;

use App\Domain\Channels\OutboxConsumers;
use App\Models\Customer;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns committed events into notifications: "waiting for your approval" in the back office for the people who may
 * approve (never the person who submitted), and — when their templates are switched on under Messaging → Templates —
 * the invoice e-mail and the payment-received message to the customer. Runs in the relay loop.
 */
final class NotificationRelay
{
    /** event => [permission, what, resource slug] */
    public const APPROVALS = [
        'purchase_order.submitted' => ['purchasing.approve', 'Purchase order', 'purchase-orders'],
        'supplier_bill.held' => ['payables.approve', 'Supplier bill (price difference)', 'supplier-bills'],
        'stock_adjustment.submitted' => ['stock.adjust.approve', 'Stock adjustment', 'stock-adjustments'],
        'goods_receipt.held' => ['stock.receive.approve', 'Delivery over the tolerance', 'goods-receipts'],
        'stock_count.submitted' => ['stock.count.approve', 'Stock count', 'stock-counts'],
        'van_settlement.submitted' => ['distribution.settle', 'Van settlement', 'van-settlements'],
        'expense_claim.submitted' => ['expenses.approve', 'Expense claim', 'expense-claims'],
    ];

    public function __construct(private readonly OutboxConsumers $consumers, private readonly Notifier $notifier, private readonly Messenger $messenger) {}

    public function run(): int
    {
        return $this->consumers->consume('notify', function (OutboxEvent $e) {
            try {
                match (true) {
                    isset(self::APPROVALS[$e->event_type]) => $this->approval($e),
                    $e->event_type === 'invoice.posted' => $this->invoice($e),
                    $e->event_type === 'customer_payment.received' => $this->payment($e),
                    default => null,
                };
            } catch (Throwable $ex) {
                report($ex); // a notification must never hold up the others
            }
        }, 200, fn (OutboxEvent $e) => isset(self::APPROVALS[$e->event_type]) || in_array($e->event_type, ['invoice.posted', 'customer_payment.received'], true));
    }

    private function approval(OutboxEvent $e): void
    {
        [$permission, $what, $slug] = self::APPROVALS[$e->event_type];
        $row = $e->payload['snapshot']['row'] ?? [];
        $branchId = $row['branch_id'] ?? (isset($row['warehouse_id']) ? DB::table('warehouses')->where('id', $row['warehouse_id'])->value('branch_id') : null);
        $number = $row['number'] ?? '';
        $this->notifier->toPermission($e->tenant_id, $permission, $branchId, "{$what} {$number} is waiting for approval",
            trim(($row['submitted_by_name'] ?? $row['created_by_name'] ?? $row['claimant_name'] ?? '').' sent it.'),
            url("/admin/{$slug}/{$e->aggregate_id}"), [$row['submitted_by'] ?? null, $row['created_by'] ?? null, $row['claimant_id'] ?? null]);
    }

    private function invoice(OutboxEvent $e): void
    {
        $row = $e->payload['snapshot']['row'] ?? [];
        $customer = Customer::withoutGlobalScopes()->find($row['customer_id'] ?? null);
        if (! $customer) {
            return;
        }
        $tenant = Tenant::find($e->tenant_id);
        $this->messenger->toCustomer($e->tenant_id, 'invoice_issued', $customer->phone, $customer->email, ['company' => $tenant->name, 'customer' => $customer->name,
            'number' => $row['number'] ?? '', 'total' => Money::display((int) ($row['total_minor'] ?? 0), $row['currency_code'] ?? $tenant->currency_code),
            'due_on' => $row['due_on'] ?? ''], $row['number'] ?? null, 'Invoice messages');
    }

    private function payment(OutboxEvent $e): void
    {
        $row = $e->payload['snapshot']['row'] ?? [];
        $customer = Customer::withoutGlobalScopes()->find($row['customer_id'] ?? null);
        if (! $customer) {
            return;
        }
        $tenant = Tenant::find($e->tenant_id);
        $balance = (int) DB::table('customer_balances')->where('customer_id', $customer->id)->value('balance_minor');
        $this->messenger->toCustomer($e->tenant_id, 'payment_received', $customer->phone, $customer->email, ['company' => $tenant->name, 'customer' => $customer->name,
            'amount' => Money::display((int) ($row['amount_minor'] ?? 0), $tenant->currency_code), 'number' => $row['number'] ?? '',
            'balance' => Money::display($balance, $tenant->currency_code)], $row['number'] ?? null, 'Payment messages');
    }
}
