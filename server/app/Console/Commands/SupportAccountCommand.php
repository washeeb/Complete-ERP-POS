<?php

namespace App\Console\Commands;

use App\Domain\Access\SupportAccount;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/** SyncLytics adds (or updates) its support account in a company, on the server. The password is typed, never passed on the command line. */
class SupportAccountCommand extends Command
{
    protected $signature = 'erp:support-account {--email=} {--name=SyncLytics Support} {--company= : the company slug, when the server has several}';

    protected $description = 'Add or update the SyncLytics support account (Superadmin, L7) for a company';

    public function handle(SupportAccount $support): int
    {
        $tenants = Tenant::orderBy('created_at')->get();
        if ($tenants->isEmpty()) {
            $this->error('No company yet. Run php artisan erp:install first.');

            return self::FAILURE;
        }
        $slug = $this->option('company') ?: ($tenants->count() === 1 ? $tenants->first()->slug
            : select('Which company?', $tenants->pluck('name', 'slug')->all()));
        $tenant = $tenants->firstWhere('slug', $slug);
        if (! $tenant) {
            $this->error("No company with the slug {$slug}.");

            return self::FAILURE;
        }
        $email = $this->option('email') ?: text('Support e-mail address', required: true);
        $pass = password('Password for the support account (12+ characters)', required: true);

        try {
            [$user, $isNew] = $support->ensure($tenant, $email, (string) $this->option('name'), $pass);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($isNew ? 'Added' : 'Updated')." the support account {$user->email} (username ".SupportAccount::USERNAME.") for {$tenant->name}.");
        $this->line('Sign in at '.url('/admin').': the authenticator app is set up at the first sign-in. The owner has been told.');

        return self::SUCCESS;
    }
}
