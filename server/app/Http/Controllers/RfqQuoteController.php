<?php

namespace App\Http\Controllers;

use App\Domain\Purchasing\RfqService;
use App\Domain\Support\DomainException;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A supplier's page for one request for quotation, opened from the link sent to them: no sign-in, that request only.
 * They enter a price per line (before VAT), what they can supply, delivery time, credit terms and how long the prices
 * hold, or decline. They can change their offer until the closing time; after it they see whether they won.
 */
class RfqQuoteController extends Controller
{
    private function invitation(string $token): RfqSupplier
    {
        abort_unless(strlen($token) === 40, 404);
        $inv = RfqSupplier::where('token', $token)->first();
        abort_unless($inv, 404, 'This link is not valid.');
        $rfq = Rfq::withoutGlobalScopes()->findOrFail($inv->rfq_id);
        app(TenantContext::class)->set($rfq->tenant_id);
        $inv->setRelation('rfq', $rfq);
        abort_if($inv->rfq->status === 'cancelled' || Supplier::withoutGlobalScopes()->whereKey($inv->supplier_id)->value('status') === 'blocked', 404,
            'This request for quotation is no longer open.');

        return $inv;
    }

    private function page(RfqSupplier $inv, ?string $error = null, ?string $done = null): View
    {
        $rfq = $inv->rfq;

        return view('portal.quote', ['inv' => $inv->fresh('quoteLines'), 'rfq' => $rfq, 'lines' => $rfq->lines, 'supplier' => Supplier::withoutGlobalScopes()->find($inv->supplier_id),
            'company' => PrintController::companyOf($rfq->tenant_id, $rfq->branch_id), 'currency' => Tenant::find($rfq->tenant_id)->currency_code,
            'error' => $error, 'done' => $done]);
    }

    public function show(string $token, RfqService $rfqs): View
    {
        $inv = $this->invitation($token);
        $rfqs->viewed($inv);

        return $this->page($inv);
    }

    public function quote(Request $request, string $token, RfqService $rfqs): View
    {
        $inv = $this->invitation($token);
        $data = $request->validate(['name' => 'required|string|max:120', 'lines' => 'array', 'lines.*.price' => 'nullable|string|max:20',
            'lines.*.qty' => 'nullable|string|max:20', 'lines.*.note' => 'nullable|string|max:255', 'delivery_days' => 'nullable|integer|min:0|max:365',
            'payment_days' => 'nullable|integer|min:0|max:365', 'valid_until' => 'nullable|date', 'note' => 'nullable|string|max:500',
            'decline' => 'nullable|string|max:500', 'action' => 'required|in:quote,decline']);
        try {
            $data['action'] === 'decline'
                ? $rfqs->decline($inv, (string) ($data['decline'] ?? ''), $data['name'])
                : $rfqs->quote($inv, $data, $data['name']);
        } catch (DomainException $e) {
            return $this->page($inv, $e->getMessage());
        }

        return $this->page($inv, null, $data['action'] === 'decline' ? 'Thank you for letting us know.' : 'Thank you: your prices are in. You can change them until the closing time.');
    }
}
