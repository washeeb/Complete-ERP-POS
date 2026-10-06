<?php

namespace App\Http\Controllers;

use App\Domain\Channels\ChannelSync;
use App\Domain\Channels\OnlineOrderService;
use App\Domain\Modules\ModuleService;
use App\Domain\Support\DomainException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Support\Qty;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Built-in online shop (spec §5): browse with live prices and availability, a cart, and
 * "reserve online, pay at pickup" checkout. Prices always come from the server's price list —
 * never from the browser. Online payment (Paystack/Hubtel) is added with the payments work.
 */
class StorefrontController extends Controller
{
    public const DEFAULT_TEXT = [
        'headline' => 'Order online, pick up in store',
        'intro' => 'Choose your items, reserve them now and pay when you collect.',
        'pickup_note' => 'We will call you when your order is ready. Bring your order number.',
    ];

    public function __construct(private readonly ChannelSync $sync) {}

    public function index(Request $request, string $slug): View
    {
        $shop = $this->shop($slug);
        $q = trim((string) $request->query('q', ''));
        $products = Product::where('is_active', true)->with('category')
            ->when($request->query('category'), fn ($x, $c) => $x->where('category_id', $c))
            ->when($q !== '', fn ($x) => $x->where(fn ($y) => $y->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%")))
            ->orderBy('name')->limit(200)->get()
            ->map(fn (Product $p) => $this->card($shop, $p))->filter(fn ($c) => $c['price_minor'] !== null)->values();

        return view('storefront.index', [
            'shop' => $shop, 'products' => $products, 'q' => $q, 'cartCount' => array_sum($this->cart($request, $shop)),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name']), 'text' => $this->text($shop),
        ]);
    }

    public function add(Request $request, string $slug): RedirectResponse
    {
        $shop = $this->shop($slug);
        $data = $request->validate(['product_id' => ['required', 'uuid'], 'qty' => ['nullable', 'integer', 'min:1', 'max:99']]);
        $card = $this->card($shop, Product::where('is_active', true)->findOrFail($data['product_id']));
        $cart = $this->cart($request, $shop);
        $want = ($cart[$card['id']] ?? 0) + (int) ($data['qty'] ?? 1);
        if ($card['available'] !== null && $want > $card['available']) {
            return back()->with('error', $card['available'] > 0 ? "Only {$card['available']} {$card['name']} available." : "{$card['name']} is out of stock.");
        }
        $cart[$card['id']] = $want;
        $request->session()->put($this->cartKey($shop), $cart);

        return back()->with('status', "{$card['name']} added to your order.");
    }

    public function cartView(Request $request, string $slug): View
    {
        $shop = $this->shop($slug);
        $lines = $this->priced($shop, $this->cart($request, $shop));

        return view('storefront.cart', ['shop' => $shop, 'lines' => $lines, 'total' => array_sum(array_column($lines, 'total_minor')),
            'text' => $this->text($shop), 'token' => (string) Str::uuid7()]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $shop = $this->shop($slug);
        $data = $request->validate(['qty' => ['array'], 'qty.*' => ['integer', 'min:0', 'max:99']]);
        $cart = array_filter(array_map('intval', $data['qty'] ?? []), fn ($n) => $n > 0);
        $request->session()->put($this->cartKey($shop), array_intersect_key($cart, $this->cart($request, $shop)));

        return redirect()->route('shop.cart', $slug);
    }

    public function checkout(Request $request, string $slug, OnlineOrderService $orders): RedirectResponse
    {
        $shop = $this->shop($slug);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{9,16}$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'note' => ['nullable', 'string', 'max:500'],
            'token' => ['required', 'uuid'], // one per cart page: a double-click or refresh cannot place two orders
            'website' => ['prohibited'],     // honeypot — people never fill it in
        ]);
        $lines = $this->priced($shop, $this->cart($request, $shop));
        if ($lines === []) {
            return redirect()->route('shop.index', $slug)->with('error', 'Your order is empty.');
        }
        try {
            $r = $orders->ingest($shop, [
                'external_id' => $data['token'], 'number' => 'WEB-'.strtoupper(substr(str_replace('-', '', $data['token']), -6)),
                'customer' => ['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null],
                'fulfilment' => 'pickup', 'payment_status' => 'pay_at_pickup', 'note' => $data['note'] ?? null,
                'lines' => array_map(fn ($l) => ['product_uom_id' => $l['unit_id'], 'qty' => (string) $l['qty'], 'unit_price_minor' => $l['price_minor']], $lines),
            ]);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $request->session()->forget($this->cartKey($shop));

        return redirect(URL::temporarySignedRoute('shop.order', now()->addDays(30), ['slug' => $slug, 'order' => $r['order']->id]));
    }

    public function order(Request $request, string $slug, string $order): View
    {
        $shop = $this->shop($slug);
        $o = SalesOrder::where('channel_id', $shop->id)->with('lines', 'branch')->findOrFail($order);

        return view('storefront.order', ['shop' => $shop, 'order' => $o, 'text' => $this->text($shop)]);
    }

    // ---------------------------------------------------------------------------------

    private function shop(string $slug): SalesChannel
    {
        $shop = SalesChannel::withoutGlobalScopes()->where('type', 'storefront')->where('status', 'active')
            ->where('settings->slug', $slug)->first() ?? abort(404);
        app(TenantContext::class)->set($shop->tenant_id);
        abort_unless(app(ModuleService::class)->isEnabled($shop->branch_id, 'storefront'), 404); // module switched off: shop closed

        return $shop;
    }

    private function text(SalesChannel $shop): array
    {
        return array_merge(self::DEFAULT_TEXT, array_filter(array_intersect_key($shop->settings ?? [], self::DEFAULT_TEXT)));
    }

    /** @return array{id: string, unit_id: string, name: string, category: ?string, price_minor: ?int, available: ?int} */
    private function card(SalesChannel $shop, Product $p): array
    {
        $unit = ProductUom::where('product_id', $p->id)->where('is_base', true)->firstOrFail();
        $s = $this->sync->stateFor($shop, $p, $unit);

        return ['id' => $p->id, 'unit_id' => $unit->id, 'name' => $p->name, 'category' => $p->category?->name,
            'price_minor' => $s['price_minor'], 'available' => $p->track_stock ? $s['available'] : null];
    }

    private function cartKey(SalesChannel $shop): string
    {
        return 'shop_cart_'.$shop->id;
    }

    /** @return array<string, int> product id => qty */
    private function cart(Request $request, SalesChannel $shop): array
    {
        return (array) $request->session()->get($this->cartKey($shop), []);
    }

    private function priced(SalesChannel $shop, array $cart): array
    {
        $out = [];
        foreach (Product::where('is_active', true)->whereIn('id', array_keys($cart))->get() as $p) {
            $c = $this->card($shop, $p);
            if ($c['price_minor'] === null) {
                continue;
            }
            $qty = $cart[$p->id];
            $out[] = $c + ['qty' => $qty, 'total_minor' => Qty::divRound($qty * 1000 * $c['price_minor'], 1000),
                'short' => $c['available'] !== null && $qty > $c['available']];
        }

        return $out;
    }

    /** Public WebSocket details for browsers and tills (the key is public by design; the secret never leaves the server). */
    public static function realtime(): ?array
    {
        if (config('broadcasting.default') !== 'reverb' || ! config('broadcasting.connections.reverb.key')) {
            return null;
        }
        $o = config('broadcasting.connections.reverb.options');

        return ['key' => config('broadcasting.connections.reverb.key'), 'host' => $o['host'], 'port' => (int) $o['port'], 'scheme' => $o['scheme']];
    }
}
