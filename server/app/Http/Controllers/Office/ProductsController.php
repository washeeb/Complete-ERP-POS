<?php

namespace App\Http\Controllers\Office;

use App\Domain\Ai\ProductWriter;
use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\Labels;
use App\Domain\Office\ProductForm;
use App\Domain\Office\ProductsScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockCostRuns\StockCostRunResource;
use App\Filament\Support\Erp;
use App\Filament\Support\QuickCreate;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Industry;
use App\Models\Product;
use App\Models\Uom;
use App\Support\IndustryContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Products in the new back office (App\Domain\Office\ProductsScreen). Every change goes through the service the current
 * screen uses (CatalogService, Labels, UnitChangeService), with its permission checks, audit row and outbox event.
 */
class ProductsController extends Controller
{
    /** Ticked products at most in one go (the current screen's whole-list tick is "every matching product"). */
    private const MAX = 5000;

    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ProductsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ProductsScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new ProductsScreen($request->user()))->show($this->product($request, $id)));
    }

    /** Print labels for the ticked products (or one): the labels open in a new tab, ready to print. */
    public function labels(Request $request, Labels $labels): JsonResponse
    {
        $this->allowed();
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Labels::KINDS))], 'layout' => ['required', Rule::in(array_keys(Labels::LAYOUTS))],
            'price_list_id' => ['nullable', 'uuid'], 'copies' => ['required', 'integer', 'min:1', 'max:500'], 'skip' => ['nullable', 'integer', 'min:0', 'max:64'],
            'give' => ['boolean'],
        ] + $this->pickRules());
        $ids = $this->picked($request)->pluck('id')->all();

        return $this->act($request, function (Actor $actor) use ($labels, $ids, $data) {
            $given = ! empty($data['give']) && Erp::canAnywhere('catalog.manage') ? $labels->giveBarcodes($actor, $ids) : 0;
            $url = $labels->prepare($actor, $ids, ['kind' => $data['kind'], 'layout' => $data['layout'], 'price_list_id' => $data['price_list_id'] ?? null,
                'copies' => (int) $data['copies'], 'skip' => (int) ($data['skip'] ?? 0)]);

            return ['url' => $url, 'count' => count($ids), 'given' => $given];
        });
    }

    /**
     * The current screen's other actions on ticked products: change category, brand or tax; set the medicine schedule;
     * switch on batches; move to an industry; change the unit. Products that cannot be changed are listed, not failed.
     */
    public function bulk(Request $request, CatalogService $catalog): JsonResponse
    {
        $this->allowed();
        $data = $request->validate([
            'action' => ['required', Rule::in(['move', 'schedule', 'batches', 'industry', 'unit'])],
            'field' => ['required_if:action,move', Rule::in(['category_id', 'brand_id', 'tax_group_id'])], 'to' => ['nullable', 'string', 'max:60'],
            'schedule' => ['required_if:action,schedule', Rule::in([...array_keys(Product::SCHEDULES), 'none'])],
            'industry' => ['required_if:action,industry', 'string', 'max:60'],
            'mode' => ['required_if:action,unit', Rule::in(['convert', 'relabel'])], 'uom_id' => ['required_if:action,unit', 'nullable', 'uuid'],
            'per' => ['nullable', 'numeric', 'min:0.001'], 'reason' => ['required_if:action,unit', 'nullable', 'string', 'max:255'],
        ] + $this->pickRules());
        abort_unless(Erp::canAnywhere('catalog.manage'), 403, 'You cannot change products.');
        $products = $this->picked($request);

        return $this->act($request, function (Actor $actor) use ($catalog, $products, $data) {
            switch ($data['action']) {
                case 'move':
                    if ($data['field'] === 'tax_group_id' && blank($data['to'] ?? null)) {
                        throw new DomainException('Choose the tax group.');
                    }

                    return ['done' => $catalog->moveProducts($actor, $products, $data['field'], $data['to'] ?? null), 'skipped' => []];
                case 'schedule':
                    $schedule = $data['schedule'] === 'none' ? null : $data['schedule'];
                    foreach ($products as $p) { // medicines are switched to batches and expiry dates, as on the current screen
                        $catalog->saveProduct($actor, $p, ['drug_schedule' => $schedule] + ($schedule && ! $p->track_batches ? ['track_batches' => true] : []),
                            'Medicine schedule set from the products list');
                    }

                    return ['done' => $products->count(), 'skipped' => []];
                case 'batches':
                    $r = $catalog->switchOnBatches($actor, $products);

                    return ['done' => $r['done'], 'untouched' => $r['untouched'], 'skipped' => $r['skipped']];
                case 'industry':
                    abort_unless(Industry::licensed()->exists() && isset(IndustryContext::choices(Erp::user())[$data['industry']]), 422, 'Choose one of your industries.');

                    return ['done' => $catalog->moveToIndustry($actor, $products, $data['industry'] === IndustryContext::SHARED ? null : $data['industry']), 'skipped' => []];
                default: // unit: each product on its own, as on the current screen; one that cannot change is listed
                    if ($data['mode'] === 'convert' && ! is_numeric($data['per'] ?? null)) {
                        throw new DomainException('Type how many of the new unit are in one of the current unit.');
                    }
                    $done = 0;
                    $skipped = [];
                    foreach ($products as $p) {
                        try {
                            DB::transaction(fn () => ProductResource::changeUnit($actor, $p, $data));
                            $done++;
                        } catch (DomainException $e) {
                            $skipped[] = "{$p->name}: {$e->getMessage()}";
                        }
                    }

                    return ['done' => $done, 'skipped' => $skipped];
            }
        }, transaction: $data['action'] !== 'unit');
    }

    /** "Export prices" and "Work out cost from selling price" are pages of their own: they take the ticked products along. */
    public function handOff(Request $request): JsonResponse
    {
        $this->allowed();
        $data = $request->validate(['to' => ['required', Rule::in(['prices', 'cost_run'])]] + $this->pickRules());
        if ($data['to'] === 'cost_run') {
            abort_unless(StockCostRunResource::canCreate(), 403, 'You cannot start a cost run.');
        }
        $ids = $this->picked($request)->pluck('id')->all();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        if ($data['to'] === 'prices') {
            session()->put('price_list.products', $ids);

            return response()->json(['url' => '/office/price-list?only=1']); // the new price list shows only these
        }
        session()->put('stock_cost_run.products', $ids); // hundreds may be ticked: too long for an address

        return response()->json(['url' => '/office/cost-runs/new']);
    }

    /** "Switch on batches for all": every stocked product not yet switched on. */
    public function batchesAll(Request $request, CatalogService $catalog): JsonResponse
    {
        $this->allowed();
        abort_unless(Erp::canAnywhere('catalog.manage'), 403, 'You cannot change products.');
        @set_time_limit(1800);

        return $this->act($request, fn (Actor $actor) => $catalog->switchOnBatches($actor, (new ProductsScreen($request->user()))->query()
            ->where('track_stock', true)->where('track_batches', false)->get()));
    }

    /** The product form: a new product (no id) or an existing one, with its choices and sections. */
    public function form(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        abort_unless(Erp::canAnywhere('catalog.manage'), 403, 'You cannot add or change products.');

        return response()->json((new ProductForm($request->user()))->form($id ? $this->product($request, $id) : null));
    }

    /** Save the form: a new product, or changes to one (with the reason, kept in the audit log). */
    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $request->validate(['values' => ['required', 'array'], 'values.name' => ['required', 'string', 'max:160'], 'values.reason' => ['nullable', 'string', 'max:255'],
            'values.description' => ['nullable', 'string', 'max:5000'], 'values.location' => ['nullable', 'string', 'max:60'],
            'values.units' => ['array', 'max:30'], 'values.prices' => ['array', 'max:200'], 'values.recipe' => ['array', 'max:100']]);
        $product = $id ? $this->product($request, $id) : new Product;

        return $this->act($request, fn (Actor $actor) => ['id' => (new ProductForm($request->user()))->save($actor, $product, (array) $request->input('values'))->id]);
    }

    /**
     * "+" next to category, brand or unit: add the missing one without leaving the form, through the same rules as its
     * own screen; one already there under that name (or code) is picked instead of a second copy.
     */
    public function quickAdd(Request $request, CatalogService $catalog): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['kind' => ['required', Rule::in(['category', 'brand', 'uom'])], 'name' => ['required', 'string', 'max:120'],
            'code' => ['required_if:kind,uom', 'nullable', 'string', 'max:10'], 'decimals' => ['nullable', 'integer', 'min:0', 'max:3']]);
        if ($existing = QuickCreate::existing($d['kind'], $d)) {
            return response()->json(['id' => $existing->getKey(), 'label' => $d['kind'] === 'uom' ? "{$existing->code} · {$existing->name}" : $existing->name, 'existed' => true]);
        }

        return $this->act($request, function (Actor $actor) use ($catalog, $d) {
            $m = match ($d['kind']) {
                'category' => $catalog->saveSimple($actor, new Category, ['name' => trim($d['name']), 'is_active' => true]),
                'brand' => $catalog->saveSimple($actor, new Brand, ['name' => trim($d['name'])]),
                default => $catalog->saveSimple($actor, new Uom, ['code' => $d['code'], 'name' => $d['name'], 'decimals' => (int) ($d['decimals'] ?? 0)]),
            };

            return ['id' => $m->getKey(), 'label' => $d['kind'] === 'uom' ? "{$m->code} · {$m->name}" : $m->name, 'existed' => false];
        });
    }

    /** Recipe ingredients: products that track stock, by unit, matching what is typed. */
    public function ingredients(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json(['results' => ProductForm::ingredients(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    /** A draft online-shop description from the product's details; nothing is saved until the form is. */
    public function describe(Request $request, string $id, ProductWriter $writer): JsonResponse
    {
        $this->allowed();
        abort_unless(Erp::canAnywhere('catalog.manage'), 403, 'You cannot change products.');
        $tone = (string) $request->validate(['tone' => ['required', Rule::in(['friendly', 'professional', 'short'])]])['tone'];
        $product = $this->product($request, $id);

        return $this->act($request, fn (Actor $actor) => ['description' => $writer->draft($actor, $product, $tone)], transaction: false);
    }

    private function pickRules(): array
    {
        return ['ids' => ['required_without:match', 'array', 'max:'.self::MAX], 'ids.*' => ['uuid'], 'match' => ['nullable', 'array']];
    }

    /** The ticked products, or every product the list's search and filters pick ("match"), inside the person's industries. */
    private function picked(Request $request): Collection
    {
        $screen = new ProductsScreen($request->user());
        $q = $request->has('match') ? $screen->filtered((array) $request->input('match')) : $screen->query()->whereIn('id', (array) $request->input('ids', []));
        $products = $q->limit(self::MAX + 1)->get();
        abort_if($products->isEmpty(), 422, 'Choose the products first.');
        abort_if($products->count() > self::MAX, 422, 'That is more than '.number_format(self::MAX).' products: narrow the list first.');

        return $products;
    }

    private function product(Request $request, string $id): Product
    {
        return (new ProductsScreen($request->user()))->query()->findOrFail($id);
    }

    /** One change through the domain service: access refused = 403, a rule broken = 422 with its explanation. */
    private function act(Request $request, Closure $fn, bool $transaction = true): JsonResponse
    {
        @set_time_limit(1800);
        $actor = Actor::user($request->user(), 'web');
        try {
            $r = $transaction ? DB::transaction(fn () => $fn($actor)) : $fn($actor);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json($r);
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(ProductsScreen::canView(), 403, 'You cannot see products.');
    }
}
