<?php

namespace App\Http\Controllers;

use App\Domain\Channels\WhatsappOrders;
use App\Models\User;
use App\Models\WhatsappOrder;
use App\Security\Authorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A photo or document a customer sent with a WhatsApp order: for staff who handle online orders, never public. */
class WhatsappMediaController extends Controller
{
    public function __invoke(Request $request, string $order, int $index, Authorizer $authz): StreamedResponse
    {
        $user = $request->user();
        $o = WhatsappOrder::withoutGlobalScopes()->find($order);
        abort_unless($user instanceof User && $o && $user->tenant_id === $o->tenant_id, 404);
        abort_unless($authz->allowsAnywhere($user, WhatsappOrders::PERMISSION), 403);
        $path = $o->media[$index]['path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
