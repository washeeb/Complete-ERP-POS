<?php

namespace App\Http\Controllers\Office;

use App\Domain\Backup\BackupService;
use App\Domain\Backup\Destinations;
use App\Domain\Launch\SupportReports;
use App\Domain\Licensing\LicenseException;
use App\Domain\Licensing\LicenseService;
use App\Domain\Office\SystemScreen;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\AuditVerifier;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\SupportReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * System in the new back office, first half (App\Domain\Office\SystemScreen): Health, Backups (back up now, check the
 * newest, destinations, download), Licence (enter a key, check in), Audit log (one entry, verify the chain), Problem
 * reports (close, send again) and Integrations — each through the same service as the current pages.
 */
class SystemController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): SystemScreen
    {
        $this->allowed($can);

        return new SystemScreen($r->user());
    }

    public function health(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SystemScreen::canHealth())->health());
    }

    // -------------------------------------------------------------------------------------------- Backups

    public function backups(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SystemScreen::canBackups())->backups());
    }

    /** run (back up now) · verify (check the newest backup): they can take minutes on a large database. */
    public function backupAction(Request $request, BackupService $svc): JsonResponse
    {
        $screen = $this->screen($request, SystemScreen::canBackups());
        $a = $request->validate(['action' => ['required', 'in:run,verify']])['action'];
        @set_time_limit(3600);
        try {
            $run = $a === 'run' ? $svc->run($request->user()->name) : $svc->verifyLatest();
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
        $message = $a === 'run' ? "Backup {$run->status}: ".number_format($run->size_bytes / 1048576, 1).' MB'.($run->error ? " — {$run->error}" : '') : (string) $run->verify_result;
        $good = $a === 'run' ? $run->status === 'ok' : ! str_starts_with((string) $run->verify_result, 'FAILED');

        return response()->json(['message' => $message, 'good' => $good, 'backups' => $screen->backups()]);
    }

    /** Add or change a destination: tested with a copy first; an empty secret keeps the saved one. */
    public function destinationSave(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, SystemScreen::canBackups());
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'type' => ['required', Rule::in(array_keys(Destinations::TYPES))], 'config' => ['nullable', 'array'],
            'config.*' => ['nullable', 'string', 'max:5000'], 'keep' => ['nullable', 'integer', 'min:1', 'max:365'], 'is_active' => ['boolean']],
            ['name.*' => 'Name the destination.', 'type.*' => 'Choose the kind of destination.', 'keep.*' => 'Keep 1 to 365 backups.']);
        $dest = $id ? BackupDestination::findOrFail($id) : new BackupDestination;
        $type = $d['type'];
        if (! Destinations::allowed($type, $dest->exists ? $dest : null)) {
            return response()->json(['error' => 'paid', 'detail' => 'Not saved: copies to cloud storage or another server need the off-site backup add-on on your licence (ask SyncLytics). A folder, such as a USB drive, is included.'], 422);
        }
        $old = $dest->exists && $dest->type === $type ? ($dest->config ?? []) : [];
        $config = [];
        foreach (array_keys(Destinations::FIELDS[$type]) as $k) {
            $v = trim((string) ($d['config'][$k] ?? ''));
            $config[$k] = $v !== '' ? $v : ($old[$k] ?? '');
        }
        try {
            Destinations::test($type, $config);
        } catch (DomainException $e) {
            return response()->json(['error' => 'test', 'detail' => 'Not saved: the test copy failed. '.$e->getMessage()], 422);
        }

        return $this->act(function (Actor $actor) use ($dest, $d, $type, $config, $screen) {
            $dest->forceFill(['name' => $d['name'], 'type' => $type, 'config' => $config, 'keep' => (int) ($d['keep'] ?? 14), 'is_active' => (bool) ($d['is_active'] ?? true)])->save();
            app(AuditLog::class)->record($actor, 'backup_destination.saved', $dest, ['type' => [null, $type], 'settings' => [null, 'changed']]);

            return ['message' => 'Destination saved — a test copy was written and removed.', 'backups' => $screen->backups()];
        });
    }

    public function download(Request $request, string $id, BackupService $svc): BinaryFileResponse|JsonResponse
    {
        $this->screen($request, SystemScreen::canBackups());
        $run = BackupRun::findOrFail($id);
        $file = $svc->localDir().DIRECTORY_SEPARATOR.$run->file_name;
        if (! is_file($file)) {
            return response()->json(['error' => 'gone', 'detail' => 'That backup is no longer on the server (only the newest are kept here).'], 404);
        }
        DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($request->user(), 'web'), 'backup.downloaded', $run, ['file' => [null, $run->file_name]]));

        return response()->download($file);
    }

    // -------------------------------------------------------------------------------------------- Licence

    public function licence(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SystemScreen::canLicence())->licence());
    }

    /** activate (the key) · checkin. */
    public function licenceAction(Request $request, LicenseService $svc): JsonResponse
    {
        $screen = $this->screen($request, SystemScreen::canLicence() && SystemScreen::canManageLicence());
        $a = $request->validate(['action' => ['required', 'in:activate,checkin']])['action'];
        if ($a === 'checkin') {
            $r = $svc->checkIn();

            return response()->json(['message' => $r['message'], 'good' => (bool) $r['ok'], 'licence' => $screen->licence()]);
        }
        $key = $request->validate(['key' => ['required', 'string', 'max:20000']], ['key.*' => 'Paste the licence key.'])['key'];
        try {
            $svc->activate(Actor::user($request->user(), 'web'), trim($key));
        } catch (LicenseException $e) {
            return response()->json(['error' => 'licence', 'detail' => 'Not activated: '.$e->getMessage()], 422);
        }

        return response()->json(['message' => 'Licence activated.', 'good' => true, 'licence' => $screen->licence()]);
    }

    // -------------------------------------------------------------------------------------------- Audit log

    public function audit(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SystemScreen::canAudit())->audit($request->query()));
    }

    public function auditShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, SystemScreen::canAudit());

        return response()->json($screen->auditEvent($screen->auditQuery()->findOrFail($id)));
    }

    public function auditVerify(Request $request, AuditVerifier $v): JsonResponse
    {
        $this->screen($request, SystemScreen::canAudit() && Erp::can('audit.verify'));
        $r = $v->verify($request->user()->tenant_id);

        return response()->json($r['ok'] ? ['good' => true, 'message' => "Audit chain intact ({$r['checked']} rows)."]
            : ['good' => false, 'message' => "Audit chain broken at row {$r['first_bad_seq']}. {$r['problem']} Treat this as a security incident: see docs/security.md."]);
    }

    // -------------------------------------------------------------------------------------------- Problem reports

    public function reports(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SystemScreen::canReports())->reports($request->query()));
    }

    /** close (what was done) · resend (the e-mail to support). */
    public function reportAction(Request $request, string $id, SupportReports $svc): JsonResponse
    {
        $screen = $this->screen($request, SystemScreen::canReports());
        $r = SupportReport::findOrFail($id);
        $a = $request->validate(['action' => ['required', 'in:close,resend']])['action'];
        if ($a === 'resend') {
            $ok = $svc->send($r);

            return response()->json(['message' => $ok ? 'Sent to support.' : 'Still not sent: '.$r->fresh()->send_error, 'good' => $ok, 'report' => $screen->report($r->fresh())]);
        }
        $res = $request->validate(['resolution' => ['required', 'string', 'max:255']], ['resolution.*' => 'Say what was done.'])['resolution'];

        return $this->act(fn (Actor $actor) => ['message' => 'Closed.', 'good' => true, 'report' => $screen->report($svc->close($actor, $r, $res))]);
    }

    public function integrations(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SystemScreen::canIntegrations())->integrations());
    }
}
