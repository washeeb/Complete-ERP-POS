<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\InvoicesScreen;
use App\Domain\Sales\ReceivablesService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Warehouse;
use App\Security\ReconfirmNeeded;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer invoices in the new back office (App\Domain\Office\InvoicesScreen): the list, one invoice, and Credit note
 * through ReceivablesService::creditNote, as on the current screen. Writing off what is owed always asks the person to
 * confirm it is them first; the answer then says where (428 with the "Confirm it's you" address), as To-do does.
 */
class InvoicesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new InvoicesScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new InvoicesScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new InvoicesScreen($request->user()))->show($this->find($id)));
    }

    /** Credit note: cancels the invoice on the customer's account; goods may come back into a warehouse. */
    public function credit(Request $request, string $id, ReceivablesService $receivables): JsonResponse
    {
        $this->allowed();
        $invoice = $this->find($id);
        $d = $request->validate(['reason' => ['required', 'string', 'max:255'], 'warehouse_id' => ['nullable', 'uuid']], ['reason.required' => 'Say why.']);
        $warehouse = null;
        if (filled($d['warehouse_id'] ?? null)) { // only a warehouse the person may receive returns into (the current list)
            abort_unless(array_key_exists($d['warehouse_id'], Fields::warehouses('sales.deliver')), 422, 'Choose one of the warehouses offered.');
            $warehouse = Warehouse::findOrFail($d['warehouse_id']);
        }
        $actor = Actor::user($request->user(), 'web');
        try {
            DB::transaction(fn () => $receivables->creditNote($actor, $invoice, $d['reason'], $warehouse));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => '/office/confirm-its-you'], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json((new InvoicesScreen($request->user()))->show($invoice->fresh()));
    }

    /** An invoice of a branch the person may see (as the current list). */
    private function find(string $id): Invoice
    {
        $invoice = Invoice::findOrFail($id);
        $branch = Branch::find($invoice->branch_id);
        abort_unless(Erp::can('sales.orders.view', $branch) || Erp::can('receivables.view', $branch), 403, 'You cannot see invoices of this branch.');

        return $invoice;
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(InvoicesScreen::canView(), 403, 'You cannot see invoices.');
    }
}
