<?php

namespace App\Domain\Services;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\LicenceKey;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Software licence keys (deferred from Electronics to here): keys bought from the publisher are loaded per product and
 * branch, and one is handed out for each unit sold — printed on the receipt. A branch only hands out
 * its own keys, so two servers never give out the same key. A return takes the key back as "returned" (never resold).
 */
final class LicenceKeyService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** Load keys (one per line). Keys already loaded are skipped. Returns how many were added. */
    public function load(Actor $actor, Product $product, Branch $branch, string $text): int
    {
        $this->can($actor, $branch);
        if (! $product->licence_keys) {
            throw new DomainException("{$product->name} does not hand out licence keys (Product → Services).");
        }
        $keys = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text)))));
        if ($keys === [] || count($keys) > 5000) {
            throw new DomainException('Paste the keys, one per line (up to 5000 at a time).');
        }
        foreach ($keys as $k) {
            if (mb_strlen($k) < 5 || mb_strlen($k) > 255) {
                throw new DomainException('A licence key is 5 to 255 characters.');
            }
        }

        return $this->recorder->transaction(function () use ($actor, $product, $branch, $keys) {
            $known = LicenceKey::whereIn('fingerprint', array_map(fn ($k) => hash('sha256', $k), $keys))->pluck('fingerprint')->flip();
            $added = 0;
            foreach ($keys as $k) {
                if (isset($known[hash('sha256', $k)])) {
                    continue;
                }
                $row = (new LicenceKey)->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $branch->id, 'product_id' => $product->id, 'licence_key' => $k,
                    'fingerprint' => hash('sha256', $k), 'hint' => mb_substr($k, -4), 'status' => 'available']);
                $this->recorder->save($actor, $row, 'licence_key.loaded', branchId: $branch->id);
                $added++;
            }

            return $added;
        });
    }

    /**
     * Inside the sale's transaction: a key for each unit of a licence-key product. Online, a product out of keys stops
     * the sale; offline it becomes a review item and the key is handed out later (Services → Licence keys).
     */
    public function handOut(Actor $actor, Sale $sale, bool $offline, array &$flags): void
    {
        $lines = DB::table('sale_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')->where('l.sale_id', $sale->id)->where('p.licence_keys', true)
            ->orderBy('l.line_no')->get(['l.id', 'l.product_id', 'l.name', 'l.qty']);
        foreach ($lines as $l) {
            $need = intdiv(Qty::toMilli((string) $l->qty), 1000);
            $got = $this->assign($actor, $sale, $l->id, $l->product_id, $need);
            if ($got < $need) {
                $offline ? $flags[] = ['licence_key_short', "{$l->name}: ".($need - $got).' licence key(s) still to give the customer']
                    : throw new DomainException("{$l->name}: no licence keys left at this branch. Load more under Services → Licence keys.", 'licence_keys_out');
            }
        }
    }

    /** Keys handed out later for a sale line that is still short (after an offline sale). */
    public function handOutLater(Actor $actor, SaleLine $line): int
    {
        $sale = Sale::findOrFail($line->sale_id);
        $this->can($actor, Branch::findOrFail($sale->branch_id));
        $short = intdiv(Qty::toMilli((string) $line->qty), 1000) - LicenceKey::where('sale_line_id', $line->id)->count();
        if ($short <= 0) {
            throw new DomainException('That sale already has all its keys.');
        }

        return $this->recorder->transaction(fn () => $this->assign($actor, $sale, $line->id, $line->product_id, $short));
    }

    /** Inside a return's transaction: the keys of the units coming back are taken back (never handed out again). */
    public function takeBack(Actor $actor, Sale $return): void
    {
        $lines = DB::table('sale_lines')->where('sale_id', $return->id)->whereNotNull('original_line_id')->get(['original_line_id', 'qty']);
        foreach ($lines as $l) {
            $keys = LicenceKey::where('sale_line_id', $l->original_line_id)->where('status', 'sold')->orderByDesc('sold_at')->lockForUpdate()
                ->limit(intdiv(abs(Qty::toMilli((string) $l->qty)), 1000))->get();
            foreach ($keys as $k) {
                $k->status = 'returned';
                $this->recorder->save($actor, $k, 'licence_key.returned', branchId: $return->branch_id, payload: ['return_id' => $return->id]);
            }
        }
    }

    public function void(Actor $actor, LicenceKey $key, string $reason): LicenceKey
    {
        $this->can($actor, Branch::findOrFail($key->branch_id));
        if ($key->status !== 'available' || trim($reason) === '') {
            throw new DomainException($key->status !== 'available' ? 'Only a key not yet sold can be voided.' : 'Give the reason.');
        }
        $key->status = 'void';

        return $this->recorder->save($actor, $key, 'licence_key.voided', $reason, $key->branch_id);
    }

    /** The keys handed out on a sale, for the receipt: [{sale_line_id, key}]. */
    public static function forSale(string $saleId): array
    {
        return LicenceKey::where('sale_id', $saleId)->orderBy('sold_at')->get()
            ->map(fn (LicenceKey $k) => ['sale_line_id' => $k->sale_line_id, 'key' => $k->licence_key])->all();
    }

    private function assign(Actor $actor, Sale $sale, string $lineId, string $productId, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        $keys = LicenceKey::where('product_id', $productId)->where('branch_id', $sale->branch_id)->where('status', 'available')
            ->orderBy('created_at')->orderBy('id')->limit($count)->lock('FOR UPDATE SKIP LOCKED')->get();
        foreach ($keys as $k) {
            $k->forceFill(['status' => 'sold', 'sale_id' => $sale->id, 'sale_line_id' => $lineId, 'sold_at' => now()]);
            $this->recorder->save($actor, $k, 'licence_key.sold', branchId: $sale->branch_id, payload: ['receipt_no' => $sale->receipt_no]);
        }

        return $keys->count();
    }

    private function can(Actor $actor, Branch $branch): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'services.licence_keys', $branch)) {
            throw new AccessDenied;
        }
    }
}
