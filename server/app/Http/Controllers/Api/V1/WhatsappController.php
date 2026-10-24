<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Channels\WhatsappOrders;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Meta's calls for WhatsApp orders: the address check when it is set up, then each message customers send. */
class WhatsappController extends Controller
{
    public function verify(Request $request, string $provider, WhatsappOrders $orders): Response
    {
        // PHP turns the dots in hub.mode / hub.verify_token / hub.challenge into underscores.
        $challenge = $orders->verify($provider, $request->query('hub_mode'), $request->query('hub_verify_token'), $request->query('hub_challenge'));

        return $challenge === null ? response('', 403) : response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $provider, WhatsappOrders $orders): Response
    {
        return response('', $orders->receive($provider, $request->getContent(), $request->header('X-Hub-Signature-256')) ? 200 : 403);
    }
}
