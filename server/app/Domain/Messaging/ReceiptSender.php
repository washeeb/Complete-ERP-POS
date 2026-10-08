<?php

namespace App\Domain\Messaging;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Message;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Support\Carbon;

/** Digital receipts: the cashier sends the receipt by SMS, WhatsApp or e-mail when the customer asks (spec §11). */
final class ReceiptSender
{
    public function __construct(private readonly Messenger $messenger, private readonly Authorizer $authz) {}

    public function send(Device $device, User $user, string $saleId, string $channel, string $to): Message
    {
        $till = Till::findOrFail($device->till_id);
        $sale = Sale::with('lines', 'payments')->find($saleId) ?? throw new DomainException('That receipt is not on the server yet. Try again when the till is back online.');
        if ($sale->branch_id !== $till->branch_id || ! $this->authz->allows($user, 'pos.access', $till)) {
            throw new AccessDenied;
        }
        if (! array_key_exists($channel, Message::CHANNELS)) {
            throw new DomainException('Choose SMS, WhatsApp or e-mail.');
        }
        $template = Templates::find($sale->tenant_id, 'receipt', $channel);
        if (! $template?->is_active) {
            throw new DomainException('Receipts by '.Message::CHANNELS[$channel].' are switched off (Messages → Templates).');
        }
        $tenant = Tenant::findOrFail($sale->tenant_id);
        $branch = Branch::find($sale->branch_id);
        $m = fn (int $v) => Money::display($v, $sale->currency_code);
        $lines = $sale->lines->map(fn ($l) => Qty::format(Qty::toMilli((string) $l->qty))." × {$l->name}  ".$m((int) $l->line_total_minor))->implode("\n");

        return $this->messenger->fromTemplate($sale->tenant_id, $channel, $to, $template, [
            'company' => $tenant->name, 'branch' => $branch?->name ?? '', 'receipt_no' => $sale->receipt_no,
            'date' => Carbon::parse($sale->occurred_at)->setTimezone($branch?->timezone ?: ($tenant->timezone ?: 'UTC'))->format('j M Y H:i'),
            'total' => $m((int) $sale->total_minor), 'items' => $sale->lines->count(), 'lines' => $lines,
        ], $sale->receipt_no, $user->name);
    }
}
