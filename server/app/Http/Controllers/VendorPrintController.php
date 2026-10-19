<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Models\Vendor\VendorInvoice;
use App\Models\Vendor\VendorLicense;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Licence server only: the licence certificate, the invoice (print or save as PDF) and the licence register (CSV). */
class VendorPrintController extends Controller
{
    private function allow(Request $request): void
    {
        abort_unless(VendorLicensing::allowed($request->user()), 403);
    }

    public function certificate(Request $request, VendorLicense $license): View
    {
        $this->allow($request);

        return view('licensing-vendor.certificate', ['l' => $license->load('client', 'plan')]);
    }

    public function invoice(Request $request, VendorInvoice $invoice): View
    {
        $this->allow($request);

        return view('licensing-vendor.invoice', ['i' => $invoice->load('customer', 'payments')]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $this->allow($request);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Licence', 'Customer', 'Site', 'Kind', 'Plan', 'Add-ons', 'Starts', 'Ends', 'Status', 'Branches', 'Devices', 'Users', 'Modules', 'Features',
                'Price', 'Currency', 'Issued by', 'Issued at', 'Replaces', 'Replaced by', 'Revoked', 'Last seen', 'Installation'], ',', '"', '');
            foreach (VendorLicense::with('client', 'plan')->orderBy('created_at')->lazy(200) as $l) {
                $p = $l->payload;
                fputcsv($out, [$l->short(), $l->client?->name ?? ($p['customer'] ?? ''), $l->site, $l->kind, $l->plan?->name,
                    collect($l->addons ?? [])->map(fn ($a) => "{$a['qty']}x {$a['name']}")->implode('; '), $l->starts_on?->toDateString(), $l->expires_on?->toDateString(),
                    VendorLicense::STATUSES[$l->status()], $p['limits']['branches'] ?? 'unlimited', $p['limits']['devices'] ?? 'unlimited', $p['limits']['users'] ?? 'unlimited',
                    ($p['modules'] ?? null) === null ? 'all' : implode('|', $p['modules']), ($p['features'] ?? null) === null ? 'all' : implode('|', $p['features']),
                    number_format(((int) $l->price_minor) / 100, 2, '.', ''), $l->currency, $l->issued_by, $l->created_at?->toDateTimeString(),
                    $l->replaces, $l->superseded_by, $l->revoked_at?->toDateTimeString(), $l->last_checkin_at?->toDateTimeString(), $l->installation_id], ',', '"', '');
            }
            fclose($out);
        }, 'licences-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
