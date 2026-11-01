<?php

namespace App\Http\Controllers;

use App\Domain\Purchasing\OrderSender;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Support\DomainException;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The supplier portal: the supplier's own page from their secret link (no sign-in). Requests for quotation, orders
 * to confirm and mark as on the way, invoices to send, and their account with us. 404 when the portal is off, the
 * link was replaced, or the supplier is blocked.
 */
class SupplierPortalController extends Controller
{
    public function __construct(private readonly SupplierPortal $portal) {}

    private function supplier(string $token): Supplier
    {
        $s = $this->portal->supplierFor($token);
        abort_unless($s, 404, 'This link is not valid any more. Ask the company for a new one.');
        app(TenantContext::class)->set($s->tenant_id);

        return $s;
    }

    public function home(string $token): View
    {
        $s = $this->supplier($token);

        return view('portal.home', ['supplier' => $s, 'token' => $token, 'company' => PrintController::companyOf($s->tenant_id, null),
            'rfqs' => $this->portal->rfqs($s), 'orders' => $this->portal->orders($s), 'statement' => $this->portal->statement($s),
            'error' => session('portal_error'), 'done' => session('portal_done')]);
    }

    public function order(string $token, string $po): View
    {
        $s = $this->supplier($token);
        $order = $this->portal->order($s, $po);
        abort_unless($order, 404);

        return view('portal.order', ['supplier' => $s, 'token' => $token, 'po' => $order, 'company' => PrintController::companyOf($s->tenant_id, $order->branch_id),
            'error' => session('portal_error'), 'done' => session('portal_done')]);
    }

    public function orderPdf(string $token, string $po): Response
    {
        $s = $this->supplier($token);
        $order = $this->portal->order($s, $po);
        abort_unless($order, 404);
        $path = app(OrderSender::class)->pdf($order);

        return Storage::disk('local')->download($path, "{$order->number}.pdf");
    }

    public function act(Request $request, string $token, string $po): RedirectResponse
    {
        $s = $this->supplier($token);
        $order = $this->portal->order($s, $po);
        abort_unless($order, 404);
        $data = $request->validate(['name' => 'required|string|max:120', 'action' => 'required|in:confirm,dispatch', 'promised_on' => 'required_if:action,confirm|nullable|date',
            'reply' => 'nullable|string|max:500', 'reference' => 'required_if:action,dispatch|nullable|string|max:120']);
        try {
            $data['action'] === 'confirm'
                ? $this->portal->confirm($s, $order, $data['promised_on'], $data['reply'] ?? null, $data['name'])
                : $this->portal->dispatch($s, $order, $data['reference'], $data['name']);
        } catch (DomainException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_done', $data['action'] === 'confirm' ? 'Thank you: the order is confirmed.' : 'Thank you: the store has been told the goods are on the way.');
    }

    public function invoice(Request $request, string $token): RedirectResponse
    {
        $s = $this->supplier($token);
        $data = $request->validate(['name' => 'required|string|max:120', 'invoice_no' => 'required|string|max:60', 'invoice_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01', 'purchase_order_id' => 'nullable|uuid', 'note' => 'nullable|string|max:500',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240']);
        $file = $request->file('file');
        $path = $file->store('supplier-invoices', 'local');
        try {
            $this->portal->submitInvoice($s, $data, $path, $file->getClientOriginalName(), $data['name']);
        } catch (DomainException $e) {
            Storage::disk('local')->delete($path);

            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_done', 'Thank you: your invoice is with our accounts team. Its progress shows below.');
    }

    public function wht(string $token, string $payment): View
    {
        $s = $this->supplier($token);
        $p = SupplierPayment::where('supplier_id', $s->id)->whereKey($payment)->first();
        abort_unless($p && $p->wht_minor > 0, 404);

        return view('print.wht-certificate', ['doc' => $p, 'company' => PrintController::companyOf($s->tenant_id, $p->branch_id), 'supplier' => $s]);
    }

    /** For staff: the invoice file a supplier sent (people who handle supplier bills or buy). */
    public function file(Request $request, SupplierInvoice $invoice, Authorizer $authz): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->tenant_id === $invoice->tenant_id, 404);
        abort_unless($authz->allowsAnywhere($user, 'payables.view') || $authz->allowsAnywhere($user, 'payables.manage') || $authz->allowsAnywhere($user, 'purchasing.manage'), 403);
        abort_unless($invoice->file_path && Storage::disk('local')->exists($invoice->file_path), 404);

        return Storage::disk('local')->response($invoice->file_path, $invoice->file_name);
    }
}
