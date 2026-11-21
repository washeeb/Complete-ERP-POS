<?php

namespace App\Http\Controllers\Office;

use App\Domain\Catalog\SharedProducts;
use App\Domain\Office\PriceListScreen;
use App\Domain\Office\SharedProductsScreen;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Industry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The price list (App\Domain\Office\PriceListScreen; its Export goes through ExportController as every list's) and
 * Shared products (App\Domain\Office\SharedProductsScreen, through SharedProducts) in the new back office, as the
 * current screens.
 */
class CatalogToolsController extends Controller
{
    use ActsOnAccess;

    public function priceList(Request $request): JsonResponse
    {
        $this->allowed(PriceListScreen::canView());

        return response()->json((new PriceListScreen($request->user()))->page($request->query()));
    }

    public function priceListOptions(Request $request): JsonResponse
    {
        $this->allowed(PriceListScreen::canView());

        return response()->json((new PriceListScreen($request->user()))->options());
    }

    public function priceListProducts(Request $request): JsonResponse
    {
        $this->allowed(PriceListScreen::canView());

        return response()->json(['rows' => (new PriceListScreen($request->user()))->products((string) $request->query('q', ''))]);
    }

    public function shared(Request $request): JsonResponse
    {
        $this->allowed(SharedProductsScreen::canView());

        return response()->json((new SharedProductsScreen($request->user()))->list($request->query()));
    }

    /** Every shared product to one industry; their stock and prices stay. */
    public function moveAll(Request $request, SharedProducts $shared): JsonResponse
    {
        $this->allowed(SharedProductsScreen::canView());
        $d = $request->validate(['industry' => ['required', Rule::in(Industry::licensed()->pluck('id')->all())]], ['industry.*' => 'Choose the industry.']);
        @set_time_limit(1800);

        return $this->act(fn (Actor $actor) => ['message' => $shared->moveAll($actor, $d['industry']).' product(s) moved.']);
    }

    /** The ticked products to their suggested industry; those with no suggestion stay. */
    public function moveSuggested(Request $request, SharedProducts $shared): JsonResponse
    {
        $this->allowed(SharedProductsScreen::canView());
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['uuid']], ['ids.required' => 'Tick the products first.']);
        $products = SharedProductsScreen::query()->whereKey($d['ids'])->get();

        return $this->act(function (Actor $actor) use ($shared, $products) {
            $n = $shared->moveSuggested($actor, $products);
            $left = $products->count() - $n;

            return ['message' => "{$n} product(s) moved.".($left ? " {$left} with no suggestion stayed." : '')];
        });
    }
}
