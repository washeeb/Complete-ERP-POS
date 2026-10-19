<?php

namespace App\Http\Controllers;

use App\Domain\Logistics\WaybillService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Models\Waybill;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The receiver's page, opened from the QR code on a printed waybill: no sign-in, only the waybill's secret link, and only
 * while the goods are on the way. They give their name (and optionally a photo of the goods or the signed copy).
 */
class WaybillConfirmController extends Controller
{
    private function waybill(string $token): Waybill
    {
        abort_unless(strlen($token) === 40, 404);
        $w = Waybill::withoutGlobalScopes()->where('confirm_token', $token)->first();
        abort_unless($w, 404, 'This waybill has already been confirmed, or the link is not valid.');
        app(TenantContext::class)->set($w->tenant_id);

        return $w;
    }

    public function show(string $token): View
    {
        $w = $this->waybill($token);

        return view('waybill-confirm', ['w' => $w, 'company' => Tenant::find($w->tenant_id)?->name, 'done' => false, 'error' => null]);
    }

    public function confirm(Request $request, string $token, WaybillService $waybills): View
    {
        $w = $this->waybill($token);
        $data = $request->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'note' => 'nullable|string|max:500',
            'photo' => 'nullable|image|max:5120']);
        $path = $request->file('photo')?->store('waybill-pod', 'local');
        try {
            $waybills->deliver(Actor::system($w->tenant_id, 'Receiver (waybill link)', 'system'), $w, $data['name'], $path, $data['note'] ?? null, $data['phone'] ?? null, 'link');
        } catch (DomainException $e) {
            return view('waybill-confirm', ['w' => $w, 'company' => Tenant::find($w->tenant_id)?->name, 'done' => false, 'error' => $e->getMessage()]);
        }

        return view('waybill-confirm', ['w' => $w->fresh(), 'company' => Tenant::find($w->tenant_id)?->name, 'done' => true, 'error' => null]);
    }
}
