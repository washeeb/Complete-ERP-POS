<?php

namespace App\Console\Commands;

use App\Domain\Access\AccessService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * Someone deleted their authenticator app and has no recovery code, and nobody in the back office is above them (the
 * owner): cleared on the server, where only the people who run it can type this. Recorded in the audit log.
 */
class MfaResetCommand extends Command
{
    protected $signature = 'erp:mfa-reset {--email= : the e-mail address or username of the person} {--reason=}';

    protected $description = 'Clear a person\'s authenticator app so they set it up again at their next sign-in';

    public function handle(AccessService $access): int
    {
        $who = strtolower(trim((string) ($this->option('email') ?: text('E-mail address or username of the person', required: true))));
        $users = User::where('email', $who)->orWhere('username', $who)->get()->reject(fn (User $u) => Tenant::isPractice($u->tenant_id));
        if ($users->count() !== 1) {
            $this->error($users->isEmpty() ? "No one signs in as {$who}." : "More than one person signs in as {$who}; use the e-mail address.");

            return self::FAILURE;
        }
        $user = $users->first();
        $company = Tenant::find($user->tenant_id)?->name;
        $reason = (string) ($this->option('reason') ?: text('Why (kept in the audit log)', default: 'Authenticator app lost; identity checked by SyncLytics support', required: true));
        if ($this->input->isInteractive() && ! confirm("Reset the two-step sign-in of {$user->name} (L{$user->security_level}, {$company})?", default: false)) {
            return self::FAILURE;
        }

        try {
            $access->resetMfa(Actor::system($user->tenant_id, 'Server (erp:mfa-reset)', 'cli'), $user, $reason);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Done. {$user->name} signs in at ".url('/admin').' with their username and password, then scans a new code with the authenticator app.');

        return self::SUCCESS;
    }
}
