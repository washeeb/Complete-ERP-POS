<?php

namespace App\Domain\Pricing;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Promotion;
use App\Security\Authorizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Promotions are pricing master data: set up at head office, sent to every branch and till. */
final class PromotionService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    public function save(Actor $actor, Promotion $p, array $data): Promotion
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'promotions.manage')) {
            throw new AccessDenied;
        }
        $type = $data['type'] ?? $p->type;
        if (! array_key_exists((string) $type, PromotionEngine::TYPES)) {
            throw new DomainException('Choose the kind of promotion.');
        }
        if (trim((string) ($data['name'] ?? $p->name)) === '') {
            throw new DomainException('Give the promotion a name customers will see on the receipt.');
        }
        $p->fill(array_intersect_key($data, array_flip(['name', 'product_ids', 'category_ids', 'value_bp', 'value_minor', 'buy_qty', 'get_qty', 'bundle_qty',
            'min_spend_minor', 'price_list_ids', 'branch_ids', 'days', 'time_from', 'time_to', 'starts_at', 'ends_at', 'is_active'])));
        $p->type = $type;
        $p->coupon_code = $type === 'coupon' ? strtoupper(trim((string) ($data['coupon_code'] ?? $p->coupon_code))) : null;
        foreach (['product_ids', 'category_ids', 'price_list_ids', 'branch_ids', 'days'] as $f) {
            $p->{$f} = array_values(array_filter((array) ($p->{$f} ?? []), fn ($v) => $v !== null && $v !== ''));
        }
        $this->validate($p);
        if (! $p->exists) {
            $p->tenant_id = $actor->tenantId;
        }

        return $this->recorder->save($actor, $p, $p->exists ? 'promotion.updated' : 'promotion.created');
    }

    /** Promotions running at a branch, on a price list, at a moment (branch local time for happy hours). */
    public static function active(string $branchId, ?string $priceListId, CarbonInterface $at): array
    {
        $tz = Branch::find($branchId)?->timezone ?: config('app.timezone');
        $local = Carbon::instance($at)->setTimezone($tz);
        $hhmm = $local->format('H:i');

        return Promotion::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->get()
            ->filter(fn (Promotion $p) => (empty($p->days) || in_array($local->dayOfWeekIso, array_map('intval', $p->days), true))
                && (! $p->time_from || ! $p->time_to || (substr($p->time_from, 0, 5) <= $hhmm && $hhmm < substr($p->time_to, 0, 5)))
                && (empty($p->branch_ids) || in_array($branchId, $p->branch_ids, true))
                && (empty($p->price_list_ids) || ($priceListId && in_array($priceListId, $p->price_list_ids, true))))
            ->map(fn (Promotion $p) => $p->toEngine())->values()->all();
    }

    private function validate(Promotion $p): void
    {
        $need = fn (bool $ok, string $msg) => $ok ?: throw new DomainException($msg);
        match ($p->type) {
            'percent' => $need($p->value_bp > 0 && $p->value_bp <= 10000, 'Enter a percentage between 0.01 and 100.'),
            'amount_off' => $need($p->value_minor > 0, 'Enter the amount off each item.'),
            'buy_x_get_y' => $need($p->buy_qty > 0 && $p->get_qty > 0 && $p->value_bp >= 0 && $p->value_bp <= 10000, 'Enter how many to buy and how many get the discount.'),
            'bundle' => $need($p->bundle_qty >= 2 && $p->value_minor > 0, 'A bundle needs at least 2 items and a bundle price.'),
            'threshold', 'coupon' => $need(($p->value_bp > 0 && $p->value_bp <= 10000) xor $p->value_minor > 0, 'Enter either a percentage or an amount off (not both).'),
        };
        if ($p->type === 'coupon') {
            $need((bool) preg_match('/^[A-Z0-9\-]{3,30}$/', (string) $p->coupon_code), 'Coupon codes use 3 to 30 letters, digits or dashes.');
            $need(! Promotion::whereRaw('upper(coupon_code) = ?', [$p->coupon_code])->when($p->exists, fn ($q) => $q->whereKeyNot($p->id))->exists(),
                "Coupon code {$p->coupon_code} is already used.");
        }
        $need(! ($p->time_from xor $p->time_to), 'Give both the start and end time of the happy hour (or neither).');
        $need(! $p->starts_at || ! $p->ends_at || Carbon::parse($p->ends_at)->gt(Carbon::parse($p->starts_at)), 'The end must be after the start.');
        foreach ($p->days ?? [] as $d) {
            $need((int) $d >= 1 && (int) $d <= 7, 'Days are 1 (Monday) to 7 (Sunday).');
        }
    }
}
