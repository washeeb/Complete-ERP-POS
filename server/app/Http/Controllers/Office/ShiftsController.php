<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ShiftsScreen;
use App\Domain\Pos\CashControl;
use App\Domain\Pos\ShiftService;
use App\Domain\Pos\TellerVaults;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Shift;
use App\Support\Exports\Exporter;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Till shifts and the till drawer in the new back office (App\Domain\Office\ShiftsScreen), with the current screens' rules. */
class ShiftsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ShiftsScreen($request->user()))->shifts($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ShiftsScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json((new ShiftsScreen($request->user()))->shift($this->find($id)));
    }

    /** The shift report (Z report) as a PDF, as on the current screen. */
    public function report(Request $request, string $id, Exporter $exporter): StreamedResponse
    {
        $s = $this->find($id);
        abort_if($s->isOpen(), 422, 'The report is made when the shift is closed.');
        ['intro' => $intro, 'sheets' => $sheets] = ShiftResource::reportContent($s);

        return $exporter->pdf(Actor::user($request->user(), 'web'), "Shift report {$s->branch->code} · {$s->till->code}", $sheets, [], 'portrait', $intro);
    }

    /** A shift no till can close any more (the till was reinstalled or replaced, or it was left open). */
    public function close(Request $request, string $id, ShiftService $shifts): JsonResponse
    {
        $s = $this->find($id);
        $data = $request->validate(['counted' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:200']]);

        return $this->run(fn ($actor) => $shifts->closeFromOffice($actor, $s, Money::toMinor((string) $data['counted'], $s->currency_code), $data['reason']), $request, $s);
    }

    /** A mistyped count: the right amount and why; the difference is posted, the original stays visible. */
    public function correct(Request $request, string $id, ShiftService $shifts): JsonResponse
    {
        $s = $this->find($id);
        $data = $request->validate(['counted' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:200']]);

        return $this->run(fn ($actor) => $shifts->correctCount($actor, $s, Money::toMinor((string) $data['counted'], $s->currency_code), $data['reason']), $request, $s);
    }

    /**
     * The manager counts a vault blind (personal vaults, light flow), note by note: when it differs from the teller's
     * count the first time, they count again from the start; the second count stands and is booked.
     */
    public function countVault(Request $request, string $id, TellerVaults $vaults): JsonResponse
    {
        $s = $this->find($id);
        $data = $request->validate(['denoms' => ['nullable', 'array'], 'denoms.*' => ['nullable', 'integer', 'min:0', 'max:100000'], 'amount' => ['nullable', 'numeric', 'min:0']]);
        $denoms = CashControl::denominations($s->currency_code) ? collect($data['denoms'] ?? [])->map(fn ($v) => (int) ($v ?? 0))->all() : null;
        $amount = $denoms === null ? Money::toMinor((string) ($data['amount'] ?? '0'), $s->currency_code) : null;
        try {
            $res = DB::transaction(fn () => $vaults->check(Actor::user($request->user(), 'web'), $s, $denoms, $amount));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $res['status'], 'shift' => (new ShiftsScreen($request->user()))->shift(Shift::findOrFail($s->id))]);
    }

    public function drawer(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ShiftsScreen($request->user()))->drawer($request->query()));
    }

    private function run(callable $call, Request $request, Shift $s): JsonResponse
    {
        try {
            DB::transaction(fn () => $call(Actor::user($request->user(), 'web')));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json((new ShiftsScreen($request->user()))->shift(Shift::findOrFail($s->id)));
    }

    private function find(string $id): Shift
    {
        $this->allowed();
        $s = Shift::findOrFail($id);
        abort_unless(Erp::can('sales.view', Branch::find($s->branch_id)), 403, 'You cannot see shifts of this branch.');

        return $s;
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(ShiftsScreen::canView(), 403, 'You cannot see till shifts.');
    }
}
