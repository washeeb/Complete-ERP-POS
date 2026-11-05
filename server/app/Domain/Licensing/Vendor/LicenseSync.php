<?php

namespace App\Domain\Licensing\Vendor;

use App\Domain\Licensing\LicenseException;
use App\Domain\Licensing\LicenseKey;
use App\Domain\Licensing\Signer;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Models\Vendor\VendorAddon;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorInvoice;
use App\Models\Vendor\VendorLicense;
use App\Models\Vendor\VendorPayment;
use App\Models\Vendor\VendorPlan;
use App\Models\Vendor\VendorSyncDevice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Two-way sync with the SES Licensing desktop app; the server is master.
 *
 * Pull: changes after a cursor, each with the record as it stands now. Push: a batch, each change naming the version it
 * was based on. Base = current version: applied, version + 1. Otherwise the answer is a conflict with the server's copy,
 * and the app merges it (fields changed on one side only) or shows it to the person. Pushes are idempotent per key.
 *
 * A licence's signed content cannot be changed by sync: the key is the truth and everything in it is read from the key.
 * Its signature is checked against the keys this server trusts before it is accepted. Revocation only goes one way.
 *
 * Invoices and payments are posted documents: they go through VendorBilling with its rules, and a push can add one but
 * never edit one. The only change an invoice takes is being voided. "Paid" is worked out here from the payments.
 */
final class LicenseSync
{
    /** In dependency order: a licence points at its customer and plan, an invoice at its customer, a payment at its invoice. */
    public const ENTITIES = ['customer' => VendorCustomer::class, 'plan' => VendorPlan::class, 'addon' => VendorAddon::class, 'license' => VendorLicense::class, 'invoice' => VendorInvoice::class, 'payment' => VendorPayment::class];

    /** What a push may change on a licence that exists. */
    public const LICENSE_MUTABLE = ['customer_id', 'plan_id', 'addons', 'kind', 'site', 'price_minor', 'currency', 'notes', 'revoked_at', 'revoked_reason', 'revoked_by', 'superseded_by'];

    private const DATE_ONLY = ['starts_on', 'expires_on', 'issued_on', 'due_on', 'received_on'];

    public function __construct(private readonly AuditLog $audit, private readonly VendorBilling $billing) {}

    /** The app's copy of what a record looks like. */
    public static function record(Model $m): array
    {
        $data = [];
        foreach ($m::SYNC_FIELDS as $f) {
            $v = $m->getAttribute($f);
            $data[$f] = $v instanceof Carbon ? (in_array($f, self::DATE_ONLY, true) ? $v->toDateString() : $v->toIso8601String()) : $v;
        }

        return ['entity' => $m::SYNC_ENTITY, 'id' => (string) $m->getKey(), 'version' => (int) $m->version, 'updated_at' => $m->updated_at?->toIso8601String(), 'deleted' => false, 'data' => $data];
    }

    /** @return array{changes: list<array>, cursor: int, has_more: bool, meta: array, server_time: string} */
    public function pull(VendorSyncDevice $device, int $cursor, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $rows = DB::table('vendor_sync_changes')->where('seq', '>', $cursor)->orderBy('seq')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $next = (int) ($rows->last()->seq ?? $cursor);
        // A record changed many times appears once, as it is now; each record travels at the place of its last change.
        $latest = [];
        foreach ($rows as $r) {
            unset($latest[$r->entity.':'.$r->record_id]);
            $latest[$r->entity.':'.$r->record_id] = $r;
        }
        $changes = [];
        foreach ($latest as $r) {
            $class = self::ENTITIES[$r->entity] ?? null;
            $m = $class ? $class::find($r->record_id) : null;
            if ($m) {
                $changes[] = self::record($m);
            } elseif ($class) {
                $changes[] = ['entity' => $r->entity, 'id' => $r->record_id, 'version' => (int) $r->version, 'updated_at' => null, 'deleted' => true, 'data' => null];
            }
        }
        if ($cursor === 0 && $changes) { // a whole-register read is worth a line in the audit log
            DB::transaction(fn () => $this->audit->record(SyncDevices::actor($device), 'vendor.sync.full_pull', $device, ['records' => [null, count($changes)]]));
        }

        return ['changes' => $changes, 'cursor' => $next, 'has_more' => $more, 'meta' => self::meta(), 'server_time' => now()->toIso8601String()];
    }

    /** What the app needs to stay in step: names offered on a licence, and the keys this server's build trusts. */
    public static function meta(): array
    {
        return ['features' => VendorLicensing::FEATURES, 'addon_features' => VendorLicensing::ADDON_FEATURES, 'modules' => VendorLicensing::modules(),
            'public_keys' => array_keys((array) config('license.public_keys')), 'server_url' => config('license.server.url') ?: config('app.url')];
    }

    /**
     * @param  list<array{entity: string, id: string, base_version: int, data: array}>  $changes
     * @return array{results: list<array>, replayed: bool}
     */
    public function push(VendorSyncDevice $device, array $changes, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($device, $changes, $idempotencyKey) {
            // The device row is the lock: the same key sent twice at once waits here, then finds the first answer.
            VendorSyncDevice::whereKey($device->id)->lockForUpdate()->first();
            $done = DB::table('vendor_sync_requests')->where('device_id', $device->id)->where('idempotency_key', $idempotencyKey)->value('response');
            if ($done !== null) {
                return ['results' => json_decode($done, true), 'replayed' => true];
            }
            $order = array_flip(array_keys(self::ENTITIES));
            $indexed = array_keys($changes);
            usort($indexed, fn ($a, $b) => [$order[$changes[$a]['entity'] ?? ''] ?? 9, $a] <=> [$order[$changes[$b]['entity'] ?? ''] ?? 9, $b]);
            $results = [];
            foreach ($indexed as $i) {
                $results[] = $this->applyOne($device, $changes[$i]);
            }
            DB::table('vendor_sync_requests')->insert(['device_id' => $device->id, 'idempotency_key' => $idempotencyKey, 'response' => json_encode($results), 'created_at' => now()]);

            return ['results' => $results, 'replayed' => false];
        });
    }

    private function applyOne(VendorSyncDevice $device, array $c): array
    {
        $entity = (string) ($c['entity'] ?? '');
        $id = (string) ($c['id'] ?? '');
        $base = (int) ($c['base_version'] ?? 0);
        $data = is_array($c['data'] ?? null) ? $c['data'] : [];
        $class = self::ENTITIES[$entity] ?? null;
        $done = fn (string $status, ?string $message = null, ?Model $m = null) => ['entity' => $entity, 'id' => $id, 'status' => $status, 'message' => $message,
            'version' => $m ? (int) $m->version : null, 'updated_at' => $m?->updated_at?->toIso8601String(), 'server' => $m && $status === 'conflict' ? self::record($m) : null];
        if (! $class || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return $done('rejected', 'Unknown kind of record or bad id.');
        }
        $m = $class::whereKey($id)->lockForUpdate()->first();
        if (! $m && $base !== 0) {
            return $done('rejected', 'This record is no longer on the server.');
        }
        if ($error = $this->invalid($entity, $id, $data)) {
            return $done('rejected', $error);
        }
        if (in_array($entity, ['invoice', 'payment'], true)) {
            try {
                return $this->applyDocument($device, $entity, $id, $base, $data, $m, $done);
            } catch (DomainException $e) {
                return $done('rejected', $e->getMessage());
            }
        }
        if (! $m) {
            if ($entity === 'license') {
                $m = $this->createLicense($device, $id, $data);
                if (is_string($m)) {
                    return $done('rejected', $m);
                }
            } else {
                $m = new $class;
                $m->id = $id;
                $m->fill(array_intersect_key($data, array_flip($m::SYNC_FIELDS)))->save();
            }
            $m = $m->fresh();
            $this->audit->record(SyncDevices::actor($device), 'vendor.sync.created', $m, ['version' => [null, 1]]);

            return $done('applied', null, $m);
        }
        $fields = $entity === 'license' ? self::LICENSE_MUTABLE : $m::SYNC_FIELDS;
        $incoming = array_intersect_key($data, array_flip($fields));
        if ($entity === 'license') {
            if ($m->revoked_at) { // revocation is final; a device that has not heard yet cannot undo it
                unset($incoming['revoked_at'], $incoming['revoked_reason'], $incoming['revoked_by']);
            }
            if ($m->superseded_by && empty($incoming['superseded_by'])) {
                unset($incoming['superseded_by']);
            }
            if (! empty($incoming['revoked_at'])) {
                $incoming['revoked_at'] = Carbon::parse($incoming['revoked_at'])->toIso8601String();
            }
        }
        $current = self::record($m)['data'];
        $changed = array_filter($incoming, fn ($v, $k) => ! self::same($v, $current[$k] ?? null), ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return $done('unchanged', null, $m);
        }
        if ($base !== (int) $m->version) {
            return $done('conflict', null, $m);
        }
        $m->forceFill($changed)->save();
        $action = $entity === 'license' && isset($changed['revoked_at']) ? 'vendor.license.revoked' : 'vendor.sync.updated';
        $diff = [];
        foreach ($changed as $k => $v) {
            $diff[$k] = [$current[$k] ?? null, $v];
        }
        $this->audit->record(SyncDevices::actor($device), $action, $m, $diff);

        return $done('applied', null, $m->fresh());
    }

    /** An invoice or payment: added once, then only an invoice can change, by being voided. VendorBilling writes the audit rows. */
    private function applyDocument(VendorSyncDevice $device, string $entity, string $id, int $base, array $d, ?Model $m, callable $done): array
    {
        $actor = SyncDevices::actor($device);
        if ($entity === 'payment') {
            if ($m) {
                return $done('unchanged', null, $m);
            }
            $inv = VendorInvoice::whereKey($d['invoice_id'])->lockForUpdate()->first();
            if (! $inv) {
                return $done('rejected', 'That invoice is not on the server.');
            }

            return $done('applied', null, $this->billing->pay($actor, $inv, $d['amount_minor'], $d['method'], $d['reference'] ?? null, $d['received_on'], $id, $d['recorded_by'])->fresh());
        }
        $void = ($d['status'] ?? null) === 'void';
        if ($void && trim((string) ($d['void_reason'] ?? '')) === '') {
            return $done('rejected', 'Give the reason for voiding.');
        }
        if (! $m) {
            $m = $this->billing->adoptInvoice($actor, $id, $d);
        } elseif (! $void || $m->status === 'void') {
            return $done('unchanged', null, $m);
        } elseif ($base !== (int) $m->version) {
            return $done('conflict', null, $m);
        }
        if ($void) {
            $this->billing->void($actor, $m, (string) ($d['void_reason'] ?? ''));
        }

        return $done('applied', null, $m->fresh());
    }

    private function createLicense(VendorSyncDevice $device, string $id, array $d): VendorLicense|string
    {
        try {
            $k = LicenseKey::decode((string) ($d['license_key'] ?? ''));
        } catch (LicenseException) {
            return 'The licence key is damaged.';
        }
        $p = $k['payload'];
        if (! Signer::verifyWithTrusted($p, $k['signature'], $p['kid'] ?? null)) {
            return 'The licence key is not signed with a key this server trusts ('.($p['kid'] ?? 'no key id').'). Add its public key first.';
        }
        if (($p['license_id'] ?? null) !== $id) {
            return 'The licence key belongs to another record.';
        }

        return VendorLicense::create(['id' => $id, 'customer' => $p['customer'], 'installation_id' => $p['installation_id'], 'payload' => $p, 'license_key' => preg_replace('/\s+/', '', $d['license_key']),
            'issued_by' => mb_substr((string) ($d['issued_by'] ?? $device->name), 0, 255), 'customer_id' => $d['customer_id'] ?? null, 'plan_id' => $d['plan_id'] ?? null, 'addons' => $d['addons'] ?? null,
            'kind' => $d['kind'] ?? 'paid', 'site' => $d['site'] ?? null, 'starts_on' => $d['starts_on'] ?? substr((string) $p['issued_at'], 0, 10), 'expires_on' => substr((string) $p['expires_at'], 0, 10),
            'price_minor' => $d['price_minor'] ?? null, 'currency' => $d['currency'] ?? null, 'replaces' => $d['replaces'] ?? null, 'request_code' => $d['request_code'] ?? null, 'notes' => $d['notes'] ?? null,
            'revoked_at' => $d['revoked_at'] ?? null, 'revoked_reason' => $d['revoked_reason'] ?? null, 'revoked_by' => $d['revoked_by'] ?? null, 'superseded_by' => $d['superseded_by'] ?? null]);
    }

    private function invalid(string $entity, string $id, array $d): ?string
    {
        $table = (self::ENTITIES[$entity])::query()->getModel()->getTable();
        $code = ['required', 'string', 'max:20', Rule::unique($table, 'code')->ignore($id)];
        $limits = ['branches' => ['nullable', 'integer', 'min:0'], 'devices' => ['nullable', 'integer', 'min:0'], 'users' => ['nullable', 'integer', 'min:0'],
            'modules' => ['nullable', 'array'], 'modules.*' => ['string', 'max:40'], 'features' => ['nullable', 'array'], 'features.*' => ['string', 'max:40'],
            'price_minor' => ['nullable', 'integer', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'is_active' => ['boolean']];
        $rules = match ($entity) {
            'customer' => ['code' => $code, 'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'], 'contact_name' => ['nullable', 'string', 'max:120'],
                'email' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'whatsapp' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255'],
                'tin' => ['nullable', 'string', 'max:40'], 'industry' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string'], 'is_active' => ['boolean']],
            'plan' => ['code' => $code, 'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:500'], 'months' => ['integer', 'min:1', 'max:120']] + $limits,
            'addon' => ['code' => $code, 'name' => ['required', 'string', 'max:80']] + $limits,
            'invoice' => ['number' => ['required', 'string', 'max:30', Rule::unique('vendor_invoices', 'number')->ignore($id)], 'customer_id' => ['required', 'uuid', Rule::exists('vendor_customers', 'id')],
                'license_id' => ['nullable', 'uuid'], 'issued_on' => ['required', 'date'], 'due_on' => ['required', 'date'], 'lines' => ['required', 'array', 'min:1', 'max:100'],
                'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.qty' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_minor' => ['required', 'integer', 'min:0'],
                'total_minor' => ['required', 'integer', 'min:0'], 'currency' => ['required', 'string', 'size:3'], 'status' => ['required', Rule::in(['open', 'paid', 'void'])], 'void_reason' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:500'], 'created_by' => ['required', 'string', 'max:255']],
            'payment' => ['invoice_id' => ['required', 'uuid'], 'received_on' => ['required', 'date'], 'amount_minor' => ['required', 'integer', 'min:1'], 'method' => ['required', Rule::in(array_keys(VendorPayment::METHODS))],
                'reference' => ['nullable', 'string', 'max:120'], 'recorded_by' => ['required', 'string', 'max:255']],
            default => ['customer_id' => ['nullable', 'uuid', Rule::exists('vendor_customers', 'id')], 'plan_id' => ['nullable', 'uuid', Rule::exists('vendor_plans', 'id')], 'addons' => ['nullable', 'array'],
                'kind' => ['nullable', Rule::in(array_keys(VendorLicense::KINDS))], 'site' => ['nullable', 'string', 'max:120'], 'price_minor' => ['nullable', 'integer', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'],
                'notes' => ['nullable', 'string'], 'revoked_at' => ['nullable', 'date'], 'revoked_reason' => ['nullable', 'string', 'max:255'], 'revoked_by' => ['nullable', 'string', 'max:255'],
                'superseded_by' => ['nullable', 'uuid'], 'replaces' => ['nullable', 'uuid'], 'request_code' => ['nullable', 'string', 'max:600'], 'starts_on' => ['nullable', 'date']],
        };
        $v = Validator::make($d, $rules);

        return $v->fails() ? $v->errors()->first() : null;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return Signer::canonical(['v' => $a === '' ? null : $a]) === Signer::canonical(['v' => $b === '' ? null : $b]);
    }
}
