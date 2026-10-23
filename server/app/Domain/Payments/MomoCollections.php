<?php

namespace App\Domain\Payments;

use App\Domain\Messaging\Phone;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Device;
use App\Models\MomoCharge;
use App\Models\MomoGateway;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mobile money that confirms itself (owner request 2026-10-23). The till sends a payment request to the customer's
 * phone; the customer approves with their PIN; the till adds the payment only once the provider says it is paid, and
 * the sale records it as "verified" with the provider's transaction id. Webhooks and checks both lead to the same
 * state, and a paid request can be used by one sale only. Typed transaction ids remain possible unless the owner chooses
 * "Payment request only" (and always for sales made while the till was offline).
 */
final class MomoCollections
{
    public function __construct(private readonly MomoProviders $providers, private readonly Settings $settings, private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** The gateway the tills of this company can use now, or null. */
    public function gateway(string $tenantId): ?MomoGateway
    {
        $g = MomoGateway::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

        return $g?->is_active ? $g : null;
    }

    /** 'manual' (type the transaction id), 'prompt' (payment request, typing still allowed) or 'prompt_only'. */
    public function mode(string $tenantId): string
    {
        $mode = (string) $this->settings->get('momo.confirm', $tenantId);

        return $mode !== 'manual' && $this->gateway($tenantId) ? $mode : 'manual';
    }

    /** @param array{id: string, network: string, phone: string, amount_minor: int} $data */
    public function request(Device $device, User $user, array $data): MomoCharge
    {
        $existing = MomoCharge::withoutGlobalScopes()->find($data['id']);
        if ($existing) {
            if ($existing->tenant_id !== $device->tenant_id || $existing->device_id !== $device->id) {
                throw new DomainException('That payment request belongs to another till.');
            }

            return $existing; // a retried request: same charge, no second prompt on the phone
        }
        $g = $this->gateway($device->tenant_id) ?? throw new DomainException('Mobile money payment requests are not set up. Take the transaction ID instead.', 'momo_not_setup');
        if (! array_key_exists($data['network'], SalePayment::MOMO_PROVIDERS)) {
            throw new DomainException('Choose the mobile money network.');
        }
        if (! Phone::looksValid($data['phone'])) {
            throw new DomainException('Enter the customer’s mobile money number, e.g. 024 123 4567.');
        }
        $amount = (int) $data['amount_minor'];
        if ($amount < 100 || $amount > 1_000_000_00) {
            throw new DomainException('Mobile money requests are from 1.00.');
        }
        $tenant = Tenant::findOrFail($device->tenant_id);
        $c = (new MomoCharge)->forceFill(['id' => $data['id'], 'tenant_id' => $device->tenant_id, 'branch_id' => $device->branch_id, 'device_id' => $device->id,
            'gateway' => $g->provider, 'network' => $data['network'], 'phone' => mb_substr(Phone::international($data['phone']), 0, 20), 'amount_minor' => $amount,
            'currency_code' => $tenant->currency_code ?: 'GHS', 'reference' => 'TL'.strtoupper(Str::random(14)), 'status' => 'pending',
            'requested_by_name' => mb_substr($user->name, 0, 120)]);
        $c->save();
        [$status, $txn, $message] = $this->providers->start($g, $c, route('momo.webhook', ['token' => $g->webhook_token]),
            (string) (config('mail.from.address') ?: 'payments@example.com'), $tenant->name.' till payment');
        $this->apply($c, $status, $txn, $message);

        return $c->fresh();
    }

    /** The state for the till; while it waits, the provider is asked at most every 5 seconds. */
    public function status(Device $device, string $id): MomoCharge
    {
        $c = MomoCharge::withoutGlobalScopes()->where('tenant_id', $device->tenant_id)->where('device_id', $device->id)->findOrFail($id);
        if (in_array($c->status, ['pending', 'otp'], true)) {
            if ($c->created_at->lt(now()->subSeconds(MomoCharge::WAIT_SECONDS))) {
                $this->refresh($c, force: true);
                if (in_array($c->status, ['pending', 'otp'], true)) {
                    $this->apply($c, 'expired', null, 'The customer did not approve in time. Ask again, or take the transaction ID.');
                }
            } else {
                $this->refresh($c);
            }
        }

        return $c->fresh();
    }

    public function otp(Device $device, string $id, string $otp): MomoCharge
    {
        $c = MomoCharge::withoutGlobalScopes()->where('tenant_id', $device->tenant_id)->where('device_id', $device->id)->findOrFail($id);
        if ($c->status !== 'otp' || $c->gateway !== 'paystack') {
            throw new DomainException('This payment is not waiting for a code.');
        }
        $g = $this->gateway($c->tenant_id) ?? throw new DomainException('Mobile money payment requests are switched off.');
        [$status, $txn, $message] = $this->providers->submitOtp($g, $c, preg_replace('/\D/', '', $otp));
        $this->apply($c, $status, $txn, $message);

        return $c->fresh();
    }

    /** A provider's callback. Paystack is trusted when signed; anything else only triggers a check with the provider. */
    public function webhook(string $token, string $body, ?string $signature): bool
    {
        $g = MomoGateway::withoutGlobalScopes()->where('webhook_token', $token)->first();
        if (! $g || ! hash_equals($g->webhook_token, $token)) {
            return false;
        }
        $data = json_decode($body, true) ?: [];
        if ($g->provider === 'paystack') {
            if (! MomoProviders::paystackSigned($g, $body, $signature)) {
                return false;
            }
            $ref = (string) ($data['data']['reference'] ?? '');
        } else {
            $ref = (string) ($data['Data']['ClientReference'] ?? $data['data']['clientReference'] ?? '');
        }
        $c = $ref !== '' ? MomoCharge::withoutGlobalScopes()->where('tenant_id', $g->tenant_id)->where('reference', $ref)->first() : null;
        if ($c && $c->status !== 'success') {
            $this->refresh($c, force: true); // the provider's own check is the truth, also for a late payment after the till gave up
        }

        return true;
    }

    /**
     * A sale's MoMo payment that names a paid request: checked, then marked used (inside the sale's transaction).
     *
     * @return array{reference: string, provider: string, payer_phone: string, status: string, momo_charge_id: string}
     */
    public function forSale(string $tenantId, string $chargeId, int $amountMinor): array
    {
        $c = MomoCharge::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($chargeId)
            ?? throw new DomainException('That mobile money payment is not known here.', 'momo_unknown');
        if ($c->status !== 'success') {
            throw new DomainException('That mobile money payment is not confirmed as paid.', 'momo_not_paid');
        }
        if ($c->sale_id !== null) {
            throw new DomainException('That mobile money payment was already used for another sale.', 'momo_used');
        }
        if ($c->amount_minor !== $amountMinor) {
            throw new DomainException('The mobile money payment is for a different amount.', 'momo_amount');
        }

        return ['reference' => mb_substr((string) ($c->provider_ref ?: $c->reference), 0, 80), 'provider' => $c->network, 'payer_phone' => $c->phone,
            'status' => 'verified', 'momo_charge_id' => $c->id];
    }

    /** Inside the sale's transaction: false when another sale took it first. */
    public function attach(string $chargeId, string $saleId): bool
    {
        return MomoCharge::withoutGlobalScopes()->whereKey($chargeId)->whereNull('sale_id')->where('status', 'success')
            ->update(['sale_id' => $saleId, 'updated_at' => now()]) === 1;
    }

    /** System → Mobile money: the provider and its keys (empty fields keep the saved keys). Audited without the keys. */
    public function saveGateway(Actor $actor, array $data): MomoGateway
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'payments.momo.manage')) {
            throw new AccessDenied;
        }
        $provider = (string) ($data['provider'] ?? '');
        if (! array_key_exists($provider, MomoGateway::FIELDS)) {
            throw new DomainException('Choose the payment provider.');
        }
        $row = MomoGateway::firstOrNew(['tenant_id' => $actor->tenantId]);
        $old = $row->exists && $row->provider === $provider ? ($row->credentials ?? []) : [];
        $cred = [];
        foreach (array_keys(MomoGateway::FIELDS[$provider]) as $field) {
            $value = trim((string) ($data['credentials'][$field] ?? ''));
            $cred[$field] = $value !== '' ? $value : ($old[$field] ?? '');
        }
        $active = (bool) ($data['is_active'] ?? false);
        if ($active && in_array('', $cred, true)) {
            throw new DomainException('Fill in '.implode(', ', MomoGateway::FIELDS[$provider]).'.');
        }
        if ($provider === 'paystack' && $cred['secret_key'] !== '' && ! preg_match('/^sk_(live|test)_[A-Za-z0-9]+$/', $cred['secret_key'])) {
            throw new DomainException('The Paystack secret key starts with sk_live_ (or sk_test_ for trying it out). Never use the public key.');
        }

        return $this->recorder->transaction(function () use ($actor, $row, $provider, $cred, $active) {
            $row->forceFill(['tenant_id' => $actor->tenantId, 'provider' => $provider, 'credentials' => $cred, 'is_active' => $active,
                'webhook_token' => $row->webhook_token ?: Str::random(40)])->save();
            $this->recorder->audit->record($actor, 'momo_gateway.saved', $row, ['provider' => [null, $provider], 'active' => [null, $active], 'credentials' => [null, 'changed']]);

            return $row;
        });
    }

    private function refresh(MomoCharge $c, bool $force = false): void
    {
        if (! $force && $c->checked_at && $c->checked_at->gt(now()->subSeconds(5))) {
            return;
        }
        $g = MomoGateway::withoutGlobalScopes()->where('tenant_id', $c->tenant_id)->first();
        if (! $g) {
            return;
        }
        [$status, $txn, $message] = $this->providers->check($g, $c);
        if ($status === 'pending' && $c->status === 'otp') {
            $status = 'otp'; // still waiting for the customer's code
        }
        $this->apply($c, $status, $txn, $message);
    }

    private function apply(MomoCharge $c, string $status, ?string $txn, ?string $message): void
    {
        DB::transaction(function () use ($c, $status, $txn, $message) {
            $row = MomoCharge::withoutGlobalScopes()->lockForUpdate()->find($c->id);
            if ($row->status === 'success') {
                return; // paid stays paid
            }
            $row->forceFill(['status' => $status, 'provider_ref' => $txn ?? $row->provider_ref, 'message' => $message !== null ? mb_substr($message, 0, 255) : $row->message,
                'checked_at' => now(), 'paid_at' => $status === 'success' ? now() : null])->save();
        });
    }
}
