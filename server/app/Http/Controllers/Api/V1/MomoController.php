<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\MomoCollections;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MomoCharge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Mobile money payment requests from a till, and the payment provider's callbacks (MomoCollections). */
class MomoController extends Controller
{
    public function request(Request $request, MomoCollections $momo): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'network' => ['required', 'string', 'max:12'], 'phone' => ['required', 'string', 'max:20'],
            'amount_minor' => ['required', 'integer', 'min:1']]);

        return response()->json(['charge' => self::json($momo->request($this->device($request), $request->user(), $data))], 201);
    }

    public function show(Request $request, string $id, MomoCollections $momo): JsonResponse
    {
        return response()->json(['charge' => self::json($momo->status($this->device($request), $id))]);
    }

    public function otp(Request $request, string $id, MomoCollections $momo): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'string', 'max:12']]);

        return response()->json(['charge' => self::json($momo->otp($this->device($request), $id, $data['otp']))]);
    }

    /** Unauthenticated: matched by the secret token in the address; Paystack's signature is checked, Hubtel's triggers a status check. */
    public function webhook(Request $request, string $token, MomoCollections $momo): Response
    {
        return response('', $momo->webhook($token, $request->getContent(), $request->header('X-Paystack-Signature') ?? $request->header('X-Signature')) ? 200 : 404);
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private static function json(MomoCharge $c): array
    {
        return ['id' => $c->id, 'status' => $c->status, 'message' => $c->message, 'network' => $c->network, 'amount_minor' => $c->amount_minor,
            'reference' => $c->provider_ref ?: $c->reference, 'phone' => $c->phone];
    }
}
