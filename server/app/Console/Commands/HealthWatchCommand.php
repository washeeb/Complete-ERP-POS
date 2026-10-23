<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\SystemHealth;
use App\Filament\Pages\SystemHealthPage;
use App\Mail\PlainMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Authorizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells someone when System → Health turns red (safety review 2026-10-23): a failed or missing backup, a failed restore
 * check, the disk or memory nearly full, the server overloaded, the licence read-only… Before, these only showed when
 * someone opened the Health page. Each problem is told once a day, on the bell and by e-mail, to the people who look
 * after the system settings (owner, IT Admin) in every company on this server.
 */
class HealthWatchCommand extends Command
{
    protected $signature = 'erp:health-watch';

    protected $description = 'Notify administrators of failing health checks (once a day per problem)';

    /** Checks that are worth telling about (the rest show on the Health page only). */
    private const WATCHED = ['Database', 'Backups', 'Restore check', 'Backup passphrase', 'Disk space', 'Server load', 'Memory', 'Licence', 'Live site settings'];

    public function handle(SystemHealth $health, Notifier $notifier, Authorizer $authz): int
    {
        $problems = collect($health->run())->filter(fn ($c) => $c['status'] === 'fail' && in_array($c['check'], self::WATCHED, true))
            ->reject(fn ($c) => Cache::has('health-watch:'.md5($c['check']).':'.now()->toDateString()))->values();
        if ($problems->isEmpty()) {
            $this->info('All watched checks are fine.');

            return self::SUCCESS;
        }
        $body = $problems->map(fn ($c) => "{$c['check']}: {$c['detail']}")->implode("\n");
        foreach (Tenant::query()->get() as $tenant) {
            $notifier->toPermission($tenant->id, 'admin.settings.manage', null, 'The server needs attention', $body, SystemHealthPage::getUrl(panel: 'admin'));
            $people = User::where('tenant_id', $tenant->id)->where('is_active', true)->get()->filter(fn (User $u) => $authz->allowsAnywhere($u, 'admin.settings.manage'));
            foreach ($people as $u) {
                try {
                    Mail::to($u->email)->send(new PlainMessage("{$tenant->name}: the server needs attention", $body."\n\nSystem → Health shows the details and what to do."));
                } catch (Throwable $e) {
                    report($e); // the bell still has it
                }
            }
        }
        foreach ($problems as $c) {
            Cache::put('health-watch:'.md5($c['check']).':'.now()->toDateString(), true, now()->addDays(2));
        }
        $this->warn($body);

        return self::SUCCESS;
    }
}
