<?php

namespace App\Http\Controllers\Office;

use App\Domain\Ai\InvoiceScanner;
use App\Domain\Catalog\CatalogService;
use App\Domain\Office\DirectPurchasesScreen;
use App\Domain\Office\SuppliersScreen;
use App\Domain\Purchasing\DirectPurchases;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Http\Controllers\Controller;
use App\Models\DirectPurchase;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Security\ReconfirmNeeded;
use App\Support\IndustryContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Direct purchases in the new back office (App\Domain\Office\DirectPurchasesScreen): the list, one purchase, the form
 * with the invoice's pages, the AI scan and adding a product, and Send for approval, Post and Cancel, through
 * DirectPurchases, InvoiceScanner and CatalogService as the current screen.
 */
class DirectPurchasesController extends Controller
{
    private const FILE_RULE = ['file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:10240'];

    /** The invoice pages this person uploaded in this sign-in (the form may attach only these, or the purchase's own). */
    private const UPLOADS = 'office.direct_purchase_uploads';

    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new DirectPurchasesScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new DirectPurchasesScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new DirectPurchasesScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $d = $id ? $this->find($id) : null;
        abort_unless($d ? DirectPurchaseResource::canEdit($d) : DirectPurchasesScreen::canCreate(), 403, $d ? 'This purchase can no longer be changed by you.' : 'You cannot enter supplier invoices.');

        return response()->json((new DirectPurchasesScreen($request->user()))->form($d));
    }

    /** Product units for a line (buying units of any active product), and the units one is sold in. */
    public function units(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json(['results' => (new SuppliersScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function unit(Request $request): JsonResponse
    {
        $this->allowed();
        $u = ProductUom::with('product', 'uom')->findOrFail((string) $request->query('id'));

        return response()->json(DirectPurchasesScreen::lineValues($u, []));
    }

    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $request->validate([
            'values' => ['required', 'array'], 'values.supplier_id' => ['required', 'uuid'], 'values.invoice_no' => ['required', 'string', 'max:60'],
            'values.invoice_date' => ['required', 'date', 'before_or_equal:today'], 'values.warehouse_id' => ['required', 'uuid'],
            'values.settlement' => ['required', Rule::in(array_keys(DirectPurchase::SETTLEMENTS))], 'values.printed_total' => ['nullable', 'numeric', 'min:0'],
            'values.attachments' => ['nullable', 'array', 'max:20'], 'values.attachments.*' => ['string', 'max:255'],
            'values.lines' => ['required', 'array', 'min:1', 'max:300'], 'values.lines.*.product_uom_id' => ['required', 'uuid'],
            'values.lines.*.qty' => ['required', 'numeric', 'gt:0'], 'values.lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'values.lines.*.new_price' => ['nullable', 'numeric', 'min:0'], 'values.lines.*.expires_on' => ['nullable', 'date'],
        ], ['values.supplier_id.required' => 'Choose the supplier.', 'values.invoice_no.required' => 'Enter the invoice number.',
            'values.invoice_date.before_or_equal' => 'An invoice cannot be dated in the future.', 'values.lines.required' => 'Add the products on the invoice.',
            'values.lines.*.product_uom_id.required' => 'Choose the product on every line (or add it with +).', 'values.lines.*.qty.gt' => 'Enter the quantity on every line.',
            'values.lines.*.unit_price.required' => 'Enter the unit price on every line.']);
        $d = $id ? $this->find($id) : new DirectPurchase;
        $v = (array) $request->input('values');
        $data = (new DirectPurchasesScreen($request->user()))->toDomain($v);
        $known = $d->exists ? (array) ($d->attachments ?? []) : [];
        $mine = (array) $request->session()->get(self::UPLOADS, []);
        foreach ($data['attachments'] as $path) { // only this purchase's pages, or pages this person uploaded just now: never another invoice's file
            abort_unless((in_array($path, $known, true) || in_array($path, $mine, true)) && Storage::disk('local')->exists($path), 422, 'A page of the invoice is missing: attach it again.');
        }

        return $this->act($request, fn (Actor $actor) => ['id' => app(DirectPurchases::class)->save($actor, $d, $data)->id]);
    }

    /** The invoice's pages (photos or PDF), kept privately; their paths go with the form. */
    public function upload(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(DirectPurchasesScreen::canCreate(), 403);
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:20'], 'files.*' => self::FILE_RULE],
            ['files.*.mimetypes' => 'Attach photos (JPEG, PNG, WebP) or a PDF.', 'files.*.max' => 'Each page must be under 10 MB.']);

        $paths = array_map(fn ($f) => $f->store('direct-purchases', 'local'), $request->file('files'));
        $this->remember($request, $paths);

        return response()->json(['paths' => $paths]);
    }

    /** "Scan the invoice": the AI reads every page and fills the form; nothing is entered until it is checked and saved. */
    public function scan(Request $request, InvoiceScanner $scanner): JsonResponse
    {
        $this->allowed();
        abort_unless(DirectPurchasesScreen::canCreate(), 403);
        $request->validate(['files' => ['required', 'array', 'min:1', 'max:20'], 'files.*' => self::FILE_RULE],
            ['files.*.mimetypes' => 'Attach photos (JPEG, PNG, WebP) or a PDF.', 'files.*.max' => 'Each page must be under 10 MB.']);
        $warehouse = Warehouse::find(array_key_first(Fields::warehouses('purchasing.direct'))) ?? abort(403, 'You cannot enter supplier invoices at any store.');
        $paths = array_map(fn ($f) => $f->store('direct-purchases', 'local'), $request->file('files'));
        $this->remember($request, $paths);

        return $this->act($request, function (Actor $actor) use ($scanner, $warehouse, $paths) {
            $form = $scanner->scanPurchase($actor, $warehouse->branch()->firstOrFail(), $paths);
            $units = ProductUom::with('product', 'uom')->whereIn('id', array_filter(array_column($form['lines'], 'product_uom_id')))->get()->keyBy('id');
            $form['lines'] = array_map(function ($l) use ($units) {
                $row = DirectPurchasesScreen::lineValues($units->get($l['product_uom_id'] ?? ''), array_map(fn ($x) => $x === null ? '' : (string) $x,
                    array_intersect_key($l, array_flip(['qty', 'unit_price', 'batch_no', 'expires_on', 'supplier_sku']))));

                return ['read_as' => $l['read_as'] ?? null] + $row;
            }, $form['lines']);
            $form['printed_total'] = $form['printed_total'] === null ? '' : (string) $form['printed_total'];
            $form['supplier_id'] = (string) ($form['supplier_id'] ?? '');

            return $form;
        });
    }

    /** A page of the invoice attached to a purchase (its number: 1, 2…). */
    public function page(string $id, int $n): StreamedResponse
    {
        $this->allowed();
        $path = array_values((array) ($this->find($id)->attachments ?? []))[$n - 1] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'There is no such page.');

        return Storage::disk('local')->download($path);
    }

    /** A product added from a line: its name, smallest unit, the pack it is bought in, tax and batches (as the current form). */
    public function addProduct(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(Erp::canAnywhere('catalog.manage'), 403, 'You cannot add products.');
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'industry_id' => ['nullable', 'string'], 'base_uom_id' => ['required', 'uuid'],
            'pack_uom_id' => ['nullable', 'uuid'], 'pack_factor' => ['nullable', 'numeric', 'min:2'], 'tax_group_id' => ['required', 'uuid'], 'track_batches' => ['boolean']],
            ['name.required' => 'Enter the product name.', 'base_uom_id.required' => 'Choose the smallest unit it is sold in.', 'tax_group_id.required' => 'Choose the tax.']);
        $pack = ! empty($d['pack_uom_id']) && (float) ($d['pack_factor'] ?? 0) > 1 && $d['pack_uom_id'] !== $d['base_uom_id'];

        return $this->act($request, function (Actor $actor) use ($d, $pack) {
            $product = app(CatalogService::class)->saveProduct($actor, new Product, ['name' => $d['name'], 'industry_id' => ($d['industry_id'] ?? null) ?: IndustryContext::defaultId(),
                'base_uom_id' => $d['base_uom_id'], 'tax_group_id' => $d['tax_group_id'], 'track_stock' => true, 'is_active' => true, 'track_batches' => (bool) ($d['track_batches'] ?? false),
                'buying_uom_id' => $pack ? $d['pack_uom_id'] : null,
                'units' => $pack ? [['uom_id' => $d['pack_uom_id'], 'factor' => (string) $d['pack_factor'], 'barcodes' => [], 'is_sellable' => true, 'is_purchasable' => true]] : []]);
            $unit = ProductUom::with('product', 'uom')->where('product_id', $product->id)->where('uom_id', $pack ? $d['pack_uom_id'] : $d['base_uom_id'])->firstOrFail();

            return DirectPurchasesScreen::lineValues($unit, []);
        });
    }

    /** submit · post · cancel (reason) */
    public function action(Request $request, string $id): JsonResponse
    {
        $this->allowed();
        $a = $request->validate(['action' => ['required', Rule::in(['submit', 'post', 'cancel'])]])['action'];
        $r = $request->validate($a === 'cancel' ? ['reason' => ['required', 'string', 'max:255']] : [], ['reason.required' => 'Say why.']);
        $d = $this->find($id);
        $svc = app(DirectPurchases::class);

        return $this->act($request, function (Actor $actor) use ($a, $r, $d, $svc) {
            match ($a) {
                'submit' => $svc->submit($actor, $d),
                'post' => $svc->post($actor, $d),
                default => $svc->cancel($actor, $d, $r['reason']),
            };

            return (new DirectPurchasesScreen($actor->user))->show($d->fresh());
        });
    }

    /** @param  list<string>  $paths */
    private function remember(Request $request, array $paths): void
    {
        $request->session()->put(self::UPLOADS, array_slice([...(array) $request->session()->get(self::UPLOADS, []), ...$paths], -100));
    }

    private function find(string $id): DirectPurchase
    {
        return DirectPurchaseResource::getEloquentQuery()->findOrFail($id);
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(DirectPurchasesScreen::canView(), 403, 'You cannot see direct purchases.');
    }
}
