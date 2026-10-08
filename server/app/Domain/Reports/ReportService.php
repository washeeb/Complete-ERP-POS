<?php

namespace App\Domain\Reports;

use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Phone;
use App\Domain\Messaging\Templates;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\SavedReport;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Exports\Exporter;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saved reports and their schedules. A scheduled report runs as the person who saved it (so it never shows more than
 * they may see), is turned into a PDF, Excel or CSV file and sent by e-mail (with the file attached), WhatsApp (a short
 * note) or to a person's back-office bell. Runs at head office every 15 minutes (`erp:reports`).
 */
final class ReportService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly ReportEngine $engine,
        private readonly Exporter $exporter,
        private readonly Messenger $messenger,
    ) {}

    /** @param array{name: string, dataset: string, definition: array, shared?: bool, schedule?: string, schedule_day?: ?int, schedule_time?: ?string, format?: string, recipients?: list<array{type: string, to: string}>} $data */
    public function save(Actor $actor, SavedReport $report, array $data): SavedReport
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'reports.build') || ($report->exists && $report->owner_id !== $actor->id)) {
            throw new AccessDenied;
        }
        $name = trim((string) ($data['name'] ?? $report->name));
        if ($name === '') {
            throw new DomainException('Give the report a name.');
        }
        $dataset = (string) ($data['dataset'] ?? $report->dataset);
        $definition = (array) ($data['definition'] ?? $report->definition);
        $this->engine->run($actor->user, $dataset, ['limit' => 1] + $definition); // checks access and that the report works
        $schedule = (string) ($data['schedule'] ?? $report->schedule ?? 'none');
        $recipients = $this->recipients((array) ($data['recipients'] ?? $report->recipients ?? []));
        if (! array_key_exists($schedule, SavedReport::SCHEDULES) || ($schedule !== 'none' && ! $recipients)) {
            throw new DomainException('A scheduled report needs at least one recipient.');
        }
        if ($schedule !== 'none' && ! $this->authz->allowsAnywhere($actor->user, 'reports.schedule')) {
            throw new AccessDenied;
        }
        $time = (string) ($data['schedule_time'] ?? $report->schedule_time ?? '07:00');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new DomainException('Give the time as HH:MM, e.g. 07:00.');
        }
        $report->forceFill(['tenant_id' => $actor->tenantId, 'name' => mb_substr($name, 0, 120), 'dataset' => $dataset, 'definition' => $definition,
            'owner_id' => $report->owner_id ?? $actor->id, 'owner_name' => $report->owner_name ?? $actor->name, 'shared' => (bool) ($data['shared'] ?? $report->shared ?? false),
            'schedule' => $schedule, 'schedule_day' => $data['schedule_day'] ?? $report->schedule_day, 'schedule_time' => $time,
            'format' => in_array($data['format'] ?? $report->format, ['pdf', 'csv', 'xlsx'], true) ? ($data['format'] ?? $report->format) : 'pdf', 'recipients' => $recipients]);
        $report->next_run_at = $schedule === 'none' ? null : self::nextRun($report, Tenant::find($actor->tenantId)?->timezone ?: 'UTC');

        return $this->recorder->save($actor, $report, $report->exists ? 'saved_report.updated' : 'saved_report.created');
    }

    public function delete(Actor $actor, SavedReport $report): void
    {
        if (! $actor->user || $report->owner_id !== $actor->id) {
            throw new AccessDenied;
        }
        $this->recorder->transaction(function () use ($actor, $report) {
            $this->recorder->audit->record($actor, 'saved_report.deleted', $report, ['name' => [$report->name, null]]);
            $report->delete();
        });
    }

    /** Run and send every scheduled report that is due. @return int reports sent */
    public function runDue(): int
    {
        $n = 0;
        foreach (SavedReport::withoutGlobalScopes()->where('schedule', '<>', 'none')->where('next_run_at', '<=', now())->orderBy('next_run_at')->limit(50)->get() as $report) {
            $tz = Tenant::find($report->tenant_id)?->timezone ?: 'UTC';
            try {
                $this->send($report);
                $report->forceFill(['last_status' => 'Sent '.now($tz)->format('j M H:i')]);
                $n++;
            } catch (Throwable $e) {
                $report->forceFill(['last_status' => 'Failed: '.Str::limit($e->getMessage(), 200)]);
            }
            $report->forceFill(['last_run_at' => now(), 'next_run_at' => self::nextRun($report, $tz, CarbonImmutable::now($tz)->addMinute())])->save();
        }

        return $n;
    }

    /** Run one report now and send it to its recipients (also "Send now" on screen). */
    public function send(SavedReport $report): void
    {
        $owner = User::withoutGlobalScopes()->find($report->owner_id);
        if (! $owner?->is_active) {
            throw new DomainException('The person who saved this report is no longer active.');
        }
        $tenant = Tenant::findOrFail($report->tenant_id);
        $actor = Actor::user($owner, 'schedule');
        $result = $this->engine->run($owner, $report->dataset, $report->definition ?? []);
        $file = $this->exporter->file($actor, $report->format, $report->name, [['title' => $report->name, 'headings' => $result['headings'], 'rows' => $result['rows']]],
            ["Period: {$result['period']}", 'Scheduled report']);
        $path = "report-runs/{$report->id}/".$file['filename'];
        Storage::disk('local')->put($path, $file['bytes']);
        $vars = ['company' => $tenant->name, 'report' => $report->name, 'period' => $result['period'], 'rows' => max(0, count($result['rows']))];
        foreach ($report->recipients ?? [] as $r) {
            match ($r['type']) {
                'email' => ($t = Templates::find($tenant->id, 'scheduled_report', 'email')) && $t->is_active
                    ? $this->messenger->fromTemplate($tenant->id, 'email', $r['to'], $t, $vars, $report->name, 'Scheduled reports', $path, $file['filename']) : null,
                'whatsapp' => ($t = Templates::find($tenant->id, 'scheduled_report', 'whatsapp')) && $t->is_active
                    ? $this->messenger->fromTemplate($tenant->id, 'whatsapp', $r['to'], $t, $vars, $report->name, 'Scheduled reports') : null,
                'user' => ($u = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($r['to']))
                    ? Notification::make()->title("{$report->name} is ready")->body("Period {$result['period']}. Open Reports to see it.")->sendToDatabase($u) : null,
                default => null,
            };
        }
    }

    /** The next time a schedule falls due after $after (local time of the company). */
    public static function nextRun(SavedReport $r, string $tz, ?CarbonImmutable $after = null): ?CarbonImmutable
    {
        $after ??= CarbonImmutable::now($tz);
        [$h, $m] = array_map('intval', explode(':', $r->schedule_time ?: '07:00'));
        $at = $after->setTime($h, $m);
        $next = match ($r->schedule) {
            'daily' => $at->lte($after) ? $at->addDay() : $at,
            'weekly' => (function () use ($at, $after, $r) {
                $day = max(1, min(7, (int) ($r->schedule_day ?: 1)));
                $c = $at->addDays(($day - $at->dayOfWeekIso + 7) % 7);

                return $c->lte($after) ? $c->addWeek() : $c;
            })(),
            'monthly' => (function () use ($at, $after, $r) {
                $day = max(1, min(28, (int) ($r->schedule_day ?: 1)));
                $c = $at->setDay($day);

                return $c->lte($after) ? $c->addMonthNoOverflow()->setDay($day) : $c;
            })(),
            default => null,
        };

        return $next?->utc();
    }

    /** @return list<array{type: string, to: string}> */
    private function recipients(array $list): array
    {
        $out = [];
        foreach ($list as $r) {
            $type = $r['type'] ?? '';
            $to = trim((string) ($r['to'] ?? ''));
            $ok = match ($type) {
                'email' => (bool) filter_var($to, FILTER_VALIDATE_EMAIL),
                'whatsapp' => Phone::looksValid($to),
                'user' => User::whereKey($to)->exists(),
                default => false,
            };
            if (! $ok) {
                throw new DomainException("Recipient “{$to}” does not look right for {$type}.");
            }
            $out[] = ['type' => $type, 'to' => $to];
        }

        return $out;
    }
}
