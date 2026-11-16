<?php

namespace App\Http\Controllers\Office;

use App\Domain\Customers\CustomerService;
use App\Domain\Office\SalesScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Sale;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Till sales in the new back office (App\Domain\Office\SalesScreen), with the current screen's rules. */
class SalesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new SalesScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new SalesScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();
        $sale = Sale::findOrFail($id);
        abort_unless(Erp::can('sales.view', Branch::find($sale->branch_id)), 403, 'You cannot see sales of this branch.');

        return response()->json((new SalesScreen($request->user()))->show($sale));
    }

    /** A walk-in who gave a name and phone, kept as a customer for next time (the sale itself never changes). */
    public function saveCustomer(Request $request, string $id, CustomerService $customers): JsonResponse
    {
        $this->allowed();
        $sale = Sale::findOrFail($id);
        abort_unless(Erp::can('sales.view', Branch::find($sale->branch_id)), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:40']]);
        try {
            $c = DB::transaction(fn () => $customers->save(Actor::user($request->user(), 'web'), new Customer, ['name' => $data['name'], 'phone' => $data['phone']]));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['customer' => ['id' => $c->id, 'name' => $c->name]], 201);
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(SalesScreen::canView(), 403, 'You cannot see till sales.');
    }
}
