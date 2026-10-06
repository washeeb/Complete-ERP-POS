<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Channels\WebhookIntake;
use App\Domain\Support\DomainException;
use App\Http\Controllers\Controller;
use App\Models\SalesChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/channels/{channel}/webhooks — the URL to paste into WooCommerce / Shopify / your site. */
class ChannelWebhookController extends Controller
{
    public function __invoke(Request $request, string $channel, WebhookIntake $intake): JsonResponse
    {
        $c = SalesChannel::withoutGlobalScopes()->whereKey($channel)->whereIn('status', ['active', 'paused'])->first()
            ?? throw new DomainException('Unknown channel.', 'not_found');
        $result = $intake->receive($c, $request);

        // Stored durably; processing happens in the relay. Providers get their 2xx straight away.
        return response()->json(['ok' => true, 'status' => $result]);
    }
}
