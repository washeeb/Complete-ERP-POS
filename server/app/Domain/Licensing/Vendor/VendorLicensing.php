<?php

namespace App\Domain\Licensing\Vendor;

use App\Domain\Licensing\LicenseException;
use App\Domain\Licensing\LicenseService;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Models\User;
use App\Models\Vendor\VendorAddon;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorLicense;
use App\Models\Vendor\VendorPlan;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The licence generator (SyncLytics only, on the licence server): a licence = a customer's installation + a plan with
 * add-ons (or limits typed in) + dates + price, signed with the private key. Renewing or changing a package issues a
 * new key for the same installation that replaces the old one; the installation picks it up at its next check-in.
 * Every action is in the audit log.
 */
final class VendorLicensing
{
    public const FEATURES = ['finance' => 'Finance (accounts, payables, banking, expenses, assets, budgets)', 'hr' => 'HR & payroll'];

    public function __construct(private readonly AuditLog $audit, private readonly VendorBilling $billing) {}

    /** May this person use the licence generator? Only on the licence server; owners, or staff named in LICENSE_VENDOR_STAFF. */
    public static function allowed(?User $user): bool
    {
        if (! $user || ! config('license.server.enabled')) {
            return false;
        }
        $staff = array_filter(array_map('trim', explode(',', strtolower((string) config('license.server.staff')))));

        return $user->is_active && ($user->security_level >= 6 || in_array(strtolower((string) $user->email), $staff, true));
    }

    /** @return array<string, string> industry modules a licence can include */
    public static function modules(): array
    {
        return collect(app(ModuleRegistry::class)->all())->filter(fn ($m) => $m->kind === 'industry')
            ->mapWithKeys(fn ($m) => [$m->key => $m->name])->all();
    }

    /**
     * Limits, modules, features and price of a plan with add-ons ([[addon_id, qty]]), before anything typed in.
     *
     * @return array{branches: ?int, devices: ?int, users: ?int, modules: ?array, features: ?array, price_minor: int, currency: string, months: int, addons: list<array>}
     */
    public function package(?VendorPlan $plan, array $addons): array
    {
        $p = ['branches' => $plan?->branches, 'devices' => $plan?->devices, 'users' => $plan?->users, 'modules' => $plan?->modules, 'features' => $plan?->features,
            'price_minor' => (int) ($plan?->price_minor ?? 0), 'currency' => $plan?->currency ?? 'GHS', 'months' => (int) ($plan?->months ?? 12), 'addons' => []];
        foreach ($addons as $a) {
            $addon = VendorAddon::find($a['addon_id'] ?? null);
            $qty = max(1, (int) ($a['qty'] ?? 1));
            if (! $addon) {
                continue;
            }
            foreach (['branches', 'devices', 'users'] as $k) {
                if ($p[$k] !== null && $addon->{$k}) {
                    $p[$k] += $addon->{$k} * $qty; // unlimited stays unlimited
                }
            }
            foreach (['modules', 'features'] as $k) {
                if ($p[$k] !== null && $addon->{$k}) {
                    $p[$k] = array_values(array_unique([...$p[$k], ...$addon->{$k}]));
                }
            }
            $p['price_minor'] += $addon->price_minor * $qty;
            $p['addons'][] = ['addon_id' => $addon->id, 'code' => $addon->code, 'name' => $addon->name, 'qty' => $qty, 'price_minor' => $addon->price_minor];
        }

        return $p;
    }

    /**
     * Issue a licence.
     *
     * @param  array{customer_id: string, request_code?: ?string, replaces?: ?string, plan_id?: ?string, addons?: array, kind?: string, site?: ?string,
     *   starts_on?: ?string, expires_on?: ?string, branches?: ?int, devices?: ?int, users?: ?int, modules?: ?array, features?: ?array, price_minor?: ?int,
     *   grace_days?: ?int, checkin_days?: ?int, notes?: ?string, invoice?: bool, due_days?: ?int}  $d  limits/modules/features/price given override the package
     */
    public function issue(Actor $actor, array $d, ?array $keys = null): VendorLicense
    {
        $customer = VendorCustomer::find($d['customer_id'] ?? null) ?? throw new DomainException('Choose the customer.');
        $old = ! empty($d['replaces']) ? VendorLicense::findOrFail($d['replaces']) : null;
        if ($old && $old->revoked_at) {
            throw new DomainException('A revoked licence cannot be renewed; issue a new one with a new installation code.');
        }
        $code = trim((string) ($d['request_code'] ?? ''));
        if ($code === '' && ! $old) {
            throw new DomainException('Paste the installation code from the customer\'s Settings → Licence page (it starts with SYNREQ-).');
        }
        try {
            $installation = $code !== '' ? LicenseService::readInstallationCode($code)
                : ['installation_id' => $old->installation_id, 'fingerprint' => $old->payload['fingerprint'] ?? 'unknown'];
        } catch (LicenseException $e) {
            throw new DomainException($e->getMessage());
        }
        $plan = ! empty($d['plan_id']) ? VendorPlan::findOrFail($d['plan_id']) : null;
        $pkg = $this->package($plan, $d['addons'] ?? []);
        $kind = in_array($d['kind'] ?? 'paid', array_keys(VendorLicense::KINDS), true) ? ($d['kind'] ?? 'paid') : 'paid';
        $starts = Carbon::parse($d['starts_on'] ?? now())->startOfDay();
        $expires = ! empty($d['expires_on']) ? Carbon::parse($d['expires_on']) : ($kind === 'trial' ? $starts->copy()->addDays(30) : $starts->copy()->addMonthsNoOverflow($pkg['months'])->subDay());
        if ($expires->lt($starts)) {
            throw new DomainException('The licence must end after it starts.');
        }
        $pick = fn (string $k) => array_key_exists($k, $d) && $d[$k] !== '' ? $d[$k] : $pkg[$k];
        $limits = [];
        foreach (['branches', 'devices', 'users'] as $k) {
            $v = $pick($k);
            $limits[$k] = $v === null ? null : max(1, (int) $v);
        }
        $modules = $pick('modules');
        $features = $pick('features');
        $price = $kind === 'paid' ? (int) (isset($d['price_minor']) && $d['price_minor'] !== null ? $d['price_minor'] : $pkg['price_minor']) : 0;
        $keys ??= VendorKeys::load();

        return DB::transaction(function () use ($actor, $d, $customer, $old, $code, $installation, $plan, $pkg, $kind, $starts, $expires, $limits, $modules, $features, $price, $keys) {
            $issued = LicenseIssuer::issue(['customer' => $customer->legal_name ?: $customer->name, 'installation' => $installation, 'expires_at' => $expires->toDateString(),
                'branches' => $limits['branches'], 'devices' => $limits['devices'], 'users' => $limits['users'],
                'features' => $features === null ? null : array_values(array_intersect($features, array_keys(self::FEATURES))),
                'modules' => $modules === null ? null : array_values(array_intersect($modules, array_keys(self::modules()))),
                'grace_days' => (int) ($d['grace_days'] ?? 14), 'checkin_days' => array_key_exists('checkin_days', $d) && $d['checkin_days'] !== null ? (int) $d['checkin_days'] : 30,
                'server_url' => config('license.server.url') ?: config('app.url')], $keys, $actor->name);
            $lic = VendorLicense::findOrFail($issued['license_id']);
            $lic->forceFill(['customer_id' => $customer->id, 'plan_id' => $plan?->id, 'addons' => $pkg['addons'] ?: null, 'kind' => $kind,
                'site' => mb_substr(trim((string) ($d['site'] ?? $old?->site ?? '')), 0, 120) ?: null, 'starts_on' => $starts->toDateString(), 'expires_on' => $expires->toDateString(),
                'price_minor' => $price, 'currency' => $pkg['currency'], 'replaces' => $old?->id, 'request_code' => $code ?: $old?->request_code,
                'notes' => trim((string) ($d['notes'] ?? '')) ?: null])->save();
            if ($old) {
                $old->forceFill(['superseded_by' => $lic->id])->save();
            }
            $this->audit->record($actor, $old ? 'vendor.license.renewed' : 'vendor.license.issued', $lic,
                ['customer' => [null, $customer->name], 'expires_on' => [$old?->expires_on?->toDateString(), $expires->toDateString()], 'limits' => [null, $limits],
                    'modules' => [null, $modules], 'features' => [null, $features], 'price_minor' => [null, $price]], $d['notes'] ?? null);
            if (! empty($d['invoice']) && $price > 0) {
                $this->billing->invoiceForLicense($actor, $lic, (int) ($d['due_days'] ?? 14));
            }

            return $lic->fresh();
        });
    }

    /** Same installation, same package, next period (or to a date); the key reaches the customer at the next check-in. */
    public function renew(Actor $actor, VendorLicense $lic, array $d = [], ?array $keys = null): VendorLicense
    {
        $from = $lic->expires_on && $lic->expires_on->isFuture() ? $lic->expires_on->copy()->addDay() : now();
        $p = $lic->payload;

        return $this->issue($actor, ['customer_id' => $lic->customer_id, 'replaces' => $lic->id, 'plan_id' => $lic->plan_id,
            'addons' => array_map(fn ($a) => ['addon_id' => $a['addon_id'], 'qty' => $a['qty']], $lic->addons ?? []), 'kind' => $d['kind'] ?? ($lic->kind === 'trial' ? 'paid' : $lic->kind),
            'starts_on' => $d['starts_on'] ?? $from->toDateString(), 'expires_on' => $d['expires_on'] ?? null,
            'branches' => $p['limits']['branches'] ?? null, 'devices' => $p['limits']['devices'] ?? null, 'users' => $p['limits']['users'] ?? null,
            'modules' => $p['modules'] ?? null, 'features' => $p['features'] ?? null, 'price_minor' => $d['price_minor'] ?? ($lic->kind === 'paid' ? $lic->price_minor : null),
            'grace_days' => $p['grace_days'] ?? 14, 'checkin_days' => $p['checkin_days'] ?? 30, 'notes' => $d['notes'] ?? 'Renewal',
            'invoice' => $d['invoice'] ?? true, 'due_days' => $d['due_days'] ?? 14], $keys);
    }

    public function revoke(Actor $actor, VendorLicense $lic, string $reason): VendorLicense
    {
        if (trim($reason) === '') {
            throw new DomainException('Give the reason (the customer sees it).');
        }
        if ($lic->revoked_at) {
            throw new DomainException('Already revoked.');
        }

        return DB::transaction(function () use ($actor, $lic, $reason) {
            $lic->forceFill(['revoked_at' => now(), 'revoked_reason' => mb_substr(trim($reason), 0, 255), 'revoked_by' => $actor->name])->save();
            $this->audit->record($actor, 'vendor.license.revoked', $lic, ['revoked_at' => [null, now()->toIso8601String()]], $reason);

            return $lic;
        });
    }

    /**
     * Health from the check-ins: last seen, version and build, use against the limits, tampering, other machines.
     *
     * @return array{last: ?object, counts: array, over: list<string>, tampered: bool, machines: int, days_silent: ?int}
     */
    public static function health(VendorLicense $lic): array
    {
        $last = DB::table('vendor_checkins')->where('license_id', $lic->id)->orderByDesc('created_at')->first();
        $report = $last ? (json_decode($last->report, true) ?: []) : [];
        $counts = $report['counts'] ?? [];
        $over = [];
        foreach (['branches', 'devices', 'users'] as $k) {
            $max = $lic->payload['limits'][$k] ?? null;
            if ($max !== null && isset($counts[$k]) && $counts[$k] > $max) {
                $over[] = "{$k} {$counts[$k]} of {$max}";
            }
        }
        $machines = DB::table('vendor_checkins')->where('license_id', $lic->id)->where('created_at', '>=', now()->subDays(30))->distinct()->count('fingerprint');

        return ['last' => $last, 'counts' => $counts, 'version' => $report['version'] ?? null, 'over' => $over,
            'tampered' => (bool) ($last->tampered ?? false), 'machines' => $machines,
            'days_silent' => $last ? (int) Carbon::parse($last->created_at)->diffInDays(now()) : null];
    }
}
