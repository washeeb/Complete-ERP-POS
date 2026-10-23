<?php

namespace App\Http\Controllers;

use App\Domain\Remote\RemoteAccess;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "View screen" / "Take control" on System → Devices (remote support phase 2): checks who may connect (the company's
 * own staff with the Remote access add-on and permission, or SyncLytics support while the owner's grant is in force),
 * records it in the audit log, says what the person at the device will see (the branch's consent rule), then opens the
 * device on the relay. The relay itself asks the technician to sign in and records the session.
 */
class RemoteConnectController extends Controller
{
    public function __invoke(Request $request, Device $device, string $mode, RemoteAccess $remote): View
    {
        abort_unless(in_array($mode, ['view', 'control'], true), 404);
        $user = $request->user();
        abort_unless($user instanceof User && $user->tenant_id === $device->tenant_id, 404);
        $relay = (string) config('remote.relay_url');
        abort_if($relay === '', 404);
        $branch = Branch::findOrFail($device->branch_id);
        $level = $user->isSupport() ? $remote->supportLevel($branch) : $remote->staffLevel($user, $branch);
        abort_unless($level === 'control' || ($level === 'view' && $mode === 'view'), 403,
            $user->isSupport() ? 'The owner has not given SyncLytics support access to this branch now (System → Support access).' : 'Remote access needs the Remote access add-on and the permission to see or control devices here.');
        $computer = $device->last_client['computer'] ?? null;
        abort_unless($computer, 422, 'This device has not told the server its computer name yet: open the till on it once with the desktop app.');

        $asks = $remote->consentFor($branch) === 'ask';
        DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($user, 'web'), $mode === 'control' ? 'remote.control_opened' : 'remote.view_opened', $device,
            ['computer' => [null, $computer], 'person_asked' => [null, $asks]]));

        return view('remote.connect', ['device' => $device, 'computer' => $computer, 'mode' => $mode, 'asks' => $asks,
            'url' => $relay.'/?gotodevicename='.rawurlencode($computer).'&viewmode=11']);
    }
}
