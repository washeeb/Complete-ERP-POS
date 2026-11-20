<?php

namespace App\Http\Controllers\Office;

use App\Domain\Assets\AssetService;
use App\Domain\Office\AssetsScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AssetClass;
use App\Security\ReconfirmNeeded;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Fixed assets in the new back office (App\Domain\Office\AssetsScreen): the register, one asset, register, dispose of, and the kinds of asset. */
class AssetsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed(AssetsScreen::canView());

        return response()->json((new AssetsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed(AssetsScreen::canView());

        return response()->json((new AssetsScreen($request->user()))->options());
    }

    public function show(Request $request, string $asset): JsonResponse
    {
        $this->allowed(AssetsScreen::canView());
        $screen = new AssetsScreen($request->user());

        return response()->json(['asset' => $screen->show($screen->find($asset) ?? abort(404))]);
    }

    /** Register an asset (its cost is posted by the supplier bill or the opening balances, not here). */
    public function store(Request $request, AssetService $assets): JsonResponse
    {
        $this->allowed(AssetsScreen::canView());
        abort_unless(AssetsScreen::canManage(), 403, 'You cannot register assets.');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'asset_class_id' => ['required', 'uuid'], 'branch_id' => ['required', 'uuid'],
            'acquired_on' => ['required', 'date', 'before_or_equal:today'], 'cost' => ['required', 'numeric', 'gt:0'], 'residual' => ['nullable', 'numeric', 'min:0'],
            'life_months' => ['nullable', 'integer', 'min:1'], 'opening_depreciation' => ['nullable', 'numeric', 'min:0'],
            'serial_no' => ['nullable', 'string', 'max:80'], 'location' => ['nullable', 'string', 'max:255'],
        ], ['name.required' => 'Name the asset.', 'asset_class_id.required' => 'Choose the kind of asset.', 'cost.gt' => 'Enter the cost.',
            'acquired_on.before_or_equal' => 'The date cannot be in the future.']);

        return $this->act(fn ($actor) => ['asset' => ['id' => ($a = $assets->register($actor, [
            'name' => $d['name'], 'asset_class_id' => $d['asset_class_id'], 'branch_id' => $d['branch_id'], 'acquired_on' => $d['acquired_on'],
            'cost_minor' => FinanceUi::toMinor((string) $d['cost']), 'residual_minor' => FinanceUi::toMinor((string) ($d['residual'] ?? 0)), 'life_months' => $d['life_months'] ?? null,
            'opening_depreciation_minor' => FinanceUi::toMinor((string) ($d['opening_depreciation'] ?? 0)), 'serial_no' => $d['serial_no'] ?? null, 'location' => $d['location'] ?? null,
        ]))->id, 'number' => $a->number, 'open' => "/office/fixed-assets/{$a->id}"]]);
    }

    /** Sold, scrapped or lost: depreciated to the month it went, then taken off the books. */
    public function dispose(Request $request, string $asset, AssetService $assets): JsonResponse
    {
        $this->allowed(AssetsScreen::canView());
        $d = $request->validate(['date' => ['required', 'date', 'before_or_equal:today'], 'reason' => ['required', 'string', 'max:255'],
            'proceeds' => ['nullable', 'numeric', 'min:0'], 'account_id' => ['nullable', 'uuid']],
            ['reason.required' => 'Say how it went: sold, scrapped, stolen…', 'date.before_or_equal' => 'The date cannot be in the future.']);
        $a = (new AssetsScreen($request->user()))->find($asset) ?? abort(404);
        $proceeds = FinanceUi::toMinor((string) ($d['proceeds'] ?? 0));
        abort_if($proceeds > 0 && empty($d['account_id']), 422, 'Choose where the money went.');

        return $this->act(fn ($actor) => ['message' => $assets->dispose($actor, $a, $d['date'], $proceeds, ! empty($d['account_id']) ? Account::findOrFail($d['account_id']) : null,
            $d['reason'])->number.' disposed of.']);
    }

    public function classes(Request $request): JsonResponse
    {
        $this->allowed(AssetsScreen::canClasses());

        return response()->json((new AssetsScreen($request->user()))->classes());
    }

    /** Add a kind of asset, or (with an id) change one: name, useful life and its three accounts. */
    public function saveClass(Request $request, AssetService $assets, ?string $class = null): JsonResponse
    {
        $this->allowed(AssetsScreen::canClasses());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'life_months' => ['required', 'integer', 'min:1'], 'cost_account_id' => ['required', 'uuid'],
            'accumulated_account_id' => ['required', 'uuid'], 'expense_account_id' => ['required', 'uuid']],
            ['name.required' => 'Name the kind of asset.', 'life_months.required' => 'Give the useful life in months.']);
        $c = $class ? AssetClass::findOrFail($class) : new AssetClass;

        return $this->act(fn ($actor) => ['class' => ['id' => $assets->saveClass($actor, $c, $d)->id]]);
    }

    private function act(callable $fn): JsonResponse
    {
        try {
            return response()->json(DB::transaction(fn () => $fn(Actor::user(request()->user(), 'web'))));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(bool $can): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless($can, 403, 'You cannot see this.');
    }
}
