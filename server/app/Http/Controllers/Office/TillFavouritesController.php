<?php

namespace App\Http\Controllers\Office;

use App\Domain\Org\OrgService;
use App\Domain\Support\Actor;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Sales → Till favourites (till new look, part 1b): the products a branch's tills show first, as tiles, in the order
 * chosen. For whoever may change the branch's till settings (pos.settings), in the branches where they may.
 */
class TillFavouritesController extends Controller
{
    use ActsOnAccess;

    public static function can(): bool
    {
        return Erp::canAnywhere('pos.settings');
    }

    /** The branches this person may set, and one branch's favourites (the first, or ?branch=). */
    public function index(Request $request): JsonResponse
    {
        $this->allowed(self::can());
        $branches = $this->branches();
        $branch = $branches->firstWhere('id', (string) $request->query('branch')) ?? $branches->first();

        return response()->json([
            'branches' => $branches->map(fn (Branch $b) => ['id' => $b->id, 'label' => "{$b->code} · {$b->name}"])->values(),
            'branch' => $branch?->id, 'max' => OrgService::MAX_FAVOURITES,
            'favourites' => $branch ? $this->rows($branch, (array) ($branch->till_favourites ?? [])) : [],
        ]);
    }

    /** Products this branch sells, for the picker (name, SKU or barcode). */
    public function products(Request $request): JsonResponse
    {
        $this->allowed(self::can());
        $branch = $this->branches()->firstWhere('id', (string) $request->query('branch'));
        $q = trim((string) $request->query('q', ''));
        abort_if(! $branch, 404);
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $found = $this->sold($branch)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%")
            ->orWhereHas('barcodes', fn ($b) => $b->where('code', $q)))->orderBy('name')->limit(20)->get(['id', 'sku', 'name']);

        return response()->json(['results' => $found->map(fn (Product $p) => ['id' => $p->id, 'label' => $p->name, 'detail' => $p->sku])->values()]);
    }

    /** Save a branch's favourites: the whole list, in order. */
    public function save(Request $request, string $id, OrgService $org): JsonResponse
    {
        $this->allowed(self::can());
        $branch = $this->branches()->firstWhere('id', $id);
        abort_if(! $branch, 404);
        $d = $request->validate(['products' => ['present', 'array', 'max:'.OrgService::MAX_FAVOURITES], 'products.*' => ['uuid']],
            ['products.max' => 'A till shows at most '.OrgService::MAX_FAVOURITES.' favourite tiles.']);

        return $this->act(function (Actor $actor) use ($org, $branch, $d) {
            $saved = $org->setTillFavourites($actor, $branch, $d['products']);

            return ['message' => 'Saved. The tills show them when they next fetch the product list (within a few minutes).',
                'favourites' => $this->rows($saved, (array) ($saved->till_favourites ?? []))];
        });
    }

    /** @return Collection<int, Branch> branches where this person may change the tills' settings */
    private function branches(): Collection
    {
        $ids = Erp::branchIds('pos.settings');

        return Branch::query()->where('is_active', true)->whereNull('archived_at')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get();
    }

    private function sold(Branch $branch)
    {
        return Product::query()->where('is_active', true)
            ->when($branch->industry_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('industry_id')->orWhere('industry_id', $branch->industry_id)));
    }

    /** The favourites in order, with name, SKU and the default price list's price of the base unit. */
    private function rows(Branch $branch, array $ids): array
    {
        $list = PriceList::where('is_default', true)->value('id');
        $products = $this->sold($branch)->whereIn('id', $ids)->with(['units' => fn ($q) => $q->where('is_base', true),
            'prices' => fn ($q) => $q->where('price_list_id', $list)])->get()->keyBy('id');
        $currency = Erp::user()?->tenant?->currency_code ?? 'GHS';

        return collect($ids)->filter(fn ($id) => $products->has($id))->map(function ($id) use ($products, $currency) {
            $p = $products[$id];
            $base = $p->units->first()?->id;
            $price = $p->prices->firstWhere('product_uom_id', $base)?->amount_minor;

            return ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'price' => $price === null ? null : Money::display((int) $price, (string) $currency)];
        })->values()->all();
    }
}
