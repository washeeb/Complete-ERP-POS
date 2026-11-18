<?php

namespace App\Http\Controllers\Office;

use App\Domain\Ai\InvoiceScanner;
use App\Domain\Fashion\ConsignmentService;
use App\Domain\Office\ProductsScreen;
use App\Domain\Office\SupplierInvoicesScreen;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Invoices from suppliers and Consignment to pay in the new back office (App\Domain\Office\SupplierInvoicesScreen):
 * the list, Read with AI, Mark entered, Turn back, and Settle, through SupplierPortal, InvoiceScanner and
 * ConsignmentService as the current screens.
 */
class SupplierInvoicesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new SupplierInvoicesScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new SupplierInvoicesScreen($request->user()))->options());
    }

    public function bills(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json(['bills' => (new SupplierInvoicesScreen($request->user()))->bills($this->find($id))]);
    }

    /** read (the AI fills a draft bill; the answer says where to check it) · mark (bill) · reject (reason) */
    public function action(Request $request, string $id): JsonResponse
    {
        $this->allowed();
        abort_unless(SupplierInvoicesScreen::canManage(), 403, 'You cannot enter supplier bills.');
        $a = $request->validate(['action' => ['required', Rule::in(['read', 'mark', 'reject'])]])['action'];
        $d = $request->validate(match ($a) {
            'mark' => ['bill' => ['nullable', 'uuid']],
            'reject' => ['reason' => ['required', 'string', 'max:255']],
            default => [],
        }, ['reason.required' => 'Say why (the supplier sees this).']);
        $r = $this->find($id);
        abort_unless($r->status === 'received', 422, 'This invoice is no longer waiting to be entered.');

        return $this->act($request, function (Actor $actor) use ($a, $d, $r) {
            if ($a === 'read') {
                abort_unless((bool) $r->file_path, 422, 'There is no file to read.');
                $branch = Branch::find($r->purchaseOrder?->branch_id) ?? Branch::where('is_hq', true)->firstOrFail();
                $doc = app(InvoiceScanner::class)->scan($actor, $branch, $r->file_path, (string) ($r->file_name ?? 'invoice'), $r->supplier, $r->id);

                return ['open' => ProductsScreen::path(SupplierBillResource::getUrl('create', ['scan' => $doc->id]))];
            }
            $a === 'mark' ? app(SupplierPortal::class)->markEntered($actor, $r, $d['bill'] ?? null) : app(SupplierPortal::class)->reject($actor, $r, $d['reason']);

            return ['row' => (new SupplierInvoicesScreen($actor->user))->rowOf($r->fresh())];
        });
    }

    public function consignment(Request $request): JsonResponse
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(SupplierInvoicesScreen::canConsignment(), 403, 'You cannot see consignment to pay.');

        return response()->json((new SupplierInvoicesScreen($request->user()))->consignment());
    }

    /** Settle: a supplier bill for everything owed up to today, approved and paid in Payables as usual. */
    public function settle(Request $request, ConsignmentService $consignment): JsonResponse
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(SupplierInvoicesScreen::canConsignment(), 403, 'You cannot settle consignment.');
        $d = $request->validate(['who' => ['required', 'string', 'regex:/^[0-9a-f-]{36}\|[0-9a-f-]{36}$/'], 'statement' => ['required', 'string', 'max:60']],
            ['statement.required' => "Enter the supplier's statement or invoice number."]);
        [$supplier, $branch] = explode('|', $d['who']);

        return $this->act($request, function (Actor $actor) use ($consignment, $supplier, $branch, $d) {
            $bill = $consignment->settle($actor, Supplier::findOrFail($supplier), Branch::findOrFail($branch), now()->toDateString(), $d['statement']);

            return ['bill' => $bill->number] + (new SupplierInvoicesScreen($actor->user))->consignment();
        });
    }

    private function find(string $id): SupplierInvoice
    {
        return SupplierInvoiceResource::getEloquentQuery()->findOrFail($id);
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(SupplierInvoicesScreen::canView(), 403, 'You cannot see invoices from suppliers.');
    }
}
