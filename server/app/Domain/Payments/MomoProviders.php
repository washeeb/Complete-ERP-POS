<?php

namespace App\Domain\Payments;

use App\Domain\Messaging\Phone;
use App\Models\MomoCharge;
use App\Models\MomoGateway;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The two payment providers that collect mobile money from all three Ghana networks:
 * - Paystack: Charge API with mobile_money (networks mtn / vod / atl); a charge.success webhook signed with the secret
 *   key (HMAC SHA-512), and Verify transaction as the check.
 * - Hubtel: Receive Money (channels mtn-gh / vodafone-gh / tigo-gh) with a callback; the callback is unsigned, so it
 *   only triggers the Transaction Status check, which is the truth.
 * - Custom gateway: any other provider (Flutterwave, ExpressPay, Korba, Theteller, MTN's own MoMo API, a bank…) behind
 *   one small HTTPS contract (docs/momo-custom-gateway.md): POST {url}/charges, GET {url}/charges/{reference},
 *   POST {url}/charges/{reference}/otp, with the shared secret as a Bearer token; callbacks signed HMAC-SHA256 with it.
 *
 * Every answer is reduced to [status, provider transaction id, message]: status is pending, otp, success or failed.
 */
final class MomoProviders
{
    private const PAYSTACK = 'https://api.paystack.co';

    private const HUBTEL_RECEIVE = 'https://rmp.hubtel.com/merchantaccount/merchants';

    private const HUBTEL_STATUS = 'https://api-txnstatus.hubtel.com/transactions';

    private const PAYSTACK_NETWORK = ['mtn' => 'mtn', 'telecel' => 'vod', 'at' => 'atl'];

    private const HUBTEL_CHANNEL = ['mtn' => 'mtn-gh', 'telecel' => 'vodafone-gh', 'at' => 'tigo-gh'];

    /** @return array{0: string, 1: ?string, 2: ?string} */
    public function start(MomoGateway $g, MomoCharge $c, string $callbackUrl, string $email, string $description): array
    {
        $cred = $g->credentials ?? [];
        try {
            if ($g->provider === 'custom') {
                $r = $this->custom($cred)->post('/charges', ['reference' => $c->reference, 'amount_minor' => $c->amount_minor, 'amount' => round($c->amount_minor / 100, 2),
                    'currency' => $c->currency_code, 'network' => $c->network, 'phone' => Phone::international($c->phone), 'callback_url' => $callbackUrl, 'description' => $description]);

                return $r->successful() ? self::customState($r->json() ?? [], $c) : ['failed', null, (string) ($r->json('message') ?? 'The payment gateway refused the request ('.$r->status().').')];
            }
            if ($g->provider === 'paystack') {
                $r = Http::withToken($cred['secret_key'] ?? '')->acceptJson()->timeout(30)->post(self::PAYSTACK.'/charge', [
                    'email' => $email, 'amount' => $c->amount_minor, 'currency' => $c->currency_code, 'reference' => $c->reference,
                    'mobile_money' => ['phone' => '0'.substr(Phone::international($c->phone), 3), 'provider' => self::PAYSTACK_NETWORK[$c->network]],
                    'metadata' => ['till_charge' => $c->id],
                ]);

                return $this->paystackState($r->json('data') ?? [], $r->successful() ? null : ($r->json('message') ?? 'The payment provider refused the request.'));
            }
            $r = Http::withBasicAuth($cred['client_id'] ?? '', $cred['client_secret'] ?? '')->acceptJson()->timeout(30)
                ->post(self::HUBTEL_RECEIVE.'/'.rawurlencode($cred['pos_sales_id'] ?? '').'/receive/mobilemoney', [
                    'CustomerName' => 'Customer', 'CustomerMsisdn' => Phone::international($c->phone), 'Channel' => self::HUBTEL_CHANNEL[$c->network],
                    'Amount' => round($c->amount_minor / 100, 2), 'PrimaryCallbackUrl' => $callbackUrl, 'Description' => mb_substr($description, 0, 100),
                    'ClientReference' => $c->reference,
                ]);
            $code = (string) $r->json('ResponseCode');

            return match ($code) {
                '0000' => ['success', $r->json('Data.TransactionId'), null],
                '0001' => ['pending', $r->json('Data.TransactionId'), null],
                default => ['failed', null, $r->json('Data.Description') ?? $r->json('Message') ?? 'The payment provider refused the request (code '.($code ?: $r->status()).').'],
            };
        } catch (Throwable $e) {
            report($e);

            return ['failed', null, 'The payment provider could not be reached. Try again, or take the transaction ID from the customer.'];
        }
    }

    /** Ask the provider how the payment stands. A provider that cannot be reached leaves it pending. @return array{0: string, 1: ?string, 2: ?string} */
    public function check(MomoGateway $g, MomoCharge $c): array
    {
        $cred = $g->credentials ?? [];
        try {
            if ($g->provider === 'custom') {
                $r = $this->custom($cred)->get('/charges/'.rawurlencode($c->reference));

                return $r->successful() ? self::customState($r->json() ?? [], $c) : ['pending', null, null];
            }
            if ($g->provider === 'paystack') {
                $r = Http::withToken($cred['secret_key'] ?? '')->acceptJson()->timeout(20)->get(self::PAYSTACK.'/transaction/verify/'.rawurlencode($c->reference));
                if ($r->status() === 404 || ! $r->successful()) {
                    return ['pending', null, null];
                }

                return $this->paystackState($r->json('data') ?? [], null, $c);
            }
            $r = Http::withBasicAuth($cred['client_id'] ?? '', $cred['client_secret'] ?? '')->acceptJson()->timeout(20)
                ->get(self::HUBTEL_STATUS.'/'.rawurlencode($cred['pos_sales_id'] ?? '').'/status', ['clientReference' => $c->reference]);
            if (! $r->successful()) {
                return ['pending', null, null];
            }
            $status = strtolower((string) ($r->json('data.status') ?? $r->json('Data.Status') ?? ''));
            $amount = (float) ($r->json('data.amount') ?? $r->json('Data.Amount') ?? 0);
            $txn = $r->json('data.transactionId') ?? $r->json('Data.TransactionId');

            return match (true) {
                $status === 'paid' && (int) round($amount * 100) >= $c->amount_minor => ['success', $txn, null],
                $status === 'paid' => ['failed', $txn, 'Paid a different amount than asked. Check it on the Hubtel dashboard.'],
                in_array($status, ['unpaid', 'failed', 'refunded', 'cancelled'], true) => ['failed', $txn, 'The customer did not pay (Hubtel: '.$status.').'],
                default => ['pending', $txn, null],
            };
        } catch (Throwable $e) {
            report($e);

            return ['pending', null, null];
        }
    }

    /** Paystack or a custom gateway: the customer's one-time code (some Telecel and AT payments). @return array{0: string, 1: ?string, 2: ?string} */
    public function submitOtp(MomoGateway $g, MomoCharge $c, string $otp): array
    {
        try {
            if ($g->provider === 'custom') {
                $r = $this->custom($g->credentials ?? [])->post('/charges/'.rawurlencode($c->reference).'/otp', ['otp' => $otp]);

                return $r->successful() ? self::customState($r->json() ?? [], $c) : ['otp', null, (string) ($r->json('message') ?? 'The code was not accepted.')];
            }
            $r = Http::withToken(($g->credentials ?? [])['secret_key'] ?? '')->acceptJson()->timeout(30)
                ->post(self::PAYSTACK.'/charge/submit_otp', ['otp' => $otp, 'reference' => $c->reference]);

            return $this->paystackState($r->json('data') ?? [], $r->successful() ? null : ($r->json('message') ?? 'The code was not accepted.'));
        } catch (Throwable $e) {
            report($e);

            return ['otp', null, 'The payment provider could not be reached. Try the code again.'];
        }
    }

    /** Is this Paystack webhook really from Paystack (signed with the company's secret key)? */
    public static function paystackSigned(MomoGateway $g, string $body, ?string $signature): bool
    {
        $key = ($g->credentials ?? [])['secret_key'] ?? '';

        return $key !== '' && $signature !== null && hash_equals(hash_hmac('sha512', $body, $key), $signature);
    }

    /** Is this callback from the custom gateway (HMAC-SHA256 of the body with the shared secret, hex, header X-Signature)? */
    public static function customSigned(MomoGateway $g, string $body, ?string $signature): bool
    {
        $secret = ($g->credentials ?? [])['secret'] ?? '';

        return $secret !== '' && $signature !== null && hash_equals(hash_hmac('sha256', $body, $secret), strtolower($signature));
    }

    private function custom(array $cred)
    {
        return Http::baseUrl(rtrim((string) ($cred['url'] ?? ''), '/'))->withToken((string) ($cred['secret'] ?? ''))->acceptJson()->asJson()->timeout(30);
    }

    /** {status: pending|otp|success|failed, transaction_id, amount_minor?, message?} @return array{0: string, 1: ?string, 2: ?string} */
    private static function customState(array $d, MomoCharge $c): array
    {
        $status = strtolower((string) ($d['status'] ?? 'pending'));
        $txn = isset($d['transaction_id']) ? (string) $d['transaction_id'] : null;
        $message = isset($d['message']) ? (string) $d['message'] : null;
        if ($status === 'success' && isset($d['amount_minor']) && (int) $d['amount_minor'] < $c->amount_minor) {
            return ['failed', $txn, 'Paid less than asked.'];
        }

        return [in_array($status, ['pending', 'otp', 'success', 'failed'], true) ? $status : 'pending', $txn, $message];
    }

    /** @return array{0: string, 1: ?string, 2: ?string} */
    private function paystackState(array $data, ?string $error, ?MomoCharge $c = null): array
    {
        if ($error !== null) {
            return ['failed', null, $error];
        }
        $status = (string) ($data['status'] ?? '');
        $txn = isset($data['id']) ? (string) $data['id'] : null;
        $message = $data['display_text'] ?? $data['gateway_response'] ?? null;

        return match ($status) {
            'success' => $c && isset($data['amount']) && (int) $data['amount'] < $c->amount_minor ? ['failed', $txn, 'Paid less than asked.'] : ['success', $txn, null],
            'send_otp' => ['otp', $txn, $message ?? 'The customer received a code: type it in.'],
            'pay_offline', 'pending', 'ongoing', 'processing', 'send_pin', 'open_url' => ['pending', $txn, $message],
            'failed', 'abandoned', 'reversed' => ['failed', $txn, $message ?? 'The customer did not pay.'],
            default => ['pending', $txn, $message],
        };
    }
}
