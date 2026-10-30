<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * A staff card (credit-card size) for till sign-in: the person's name and a QR code the till's scanner reads in place of
 * the username. The code itself is not printed as text, and it is shown only this once (only its hash is kept).
 */
final class StaffCard
{
    public static function html(User $user, string $code): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
        $company = $e((string) Tenant::find($user->tenant_id)?->name);
        $qr = Qr::svg($code, 150);

        return <<<HTML
            <style>
                #staff-card { width: 85.6mm; height: 54mm; box-sizing: border-box; border: 1px solid #999; border-radius: 3mm; padding: 4mm;
                    display: flex; gap: 4mm; align-items: center; background: #fff; color: #000; font-family: system-ui, sans-serif; }
                #staff-card .qr svg { width: 34mm; height: 34mm; display: block; }
                #staff-card .who { display: flex; flex-direction: column; gap: 1.5mm; }
                #staff-card .co { font-size: 9pt; opacity: .75; } #staff-card .name { font-size: 14pt; font-weight: 700; }
                #staff-card .hint { font-size: 7.5pt; opacity: .75; }
                @media print { body * { visibility: hidden; } #staff-card, #staff-card * { visibility: visible; } #staff-card { position: fixed; left: 10mm; top: 10mm; } }
            </style>
            <div id="staff-card">
                <div class="qr">{$qr}</div>
                <div class="who"><span class="co">{$company}</span><span class="name">{$e($user->name)}</span>
                    <span class="hint">Scan at the till, then type your PIN. Lost card? Tell your manager at once.</span></div>
            </div>
            <p style="margin-top:12px"><button type="button" onclick="window.print()" style="padding:6px 14px;border:1px solid #888;border-radius:6px">Print the card</button></p>
            <p style="font-size:.85rem;opacity:.75">This card is shown only now. Print it before closing; if it is lost later, press <strong>New staff card</strong> (the old one stops working).</p>
            HTML;
    }
}
