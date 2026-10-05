<?php

namespace App\Console\Commands;

use App\Domain\Org\TenantInstaller;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'erp:install {--company=} {--name=} {--username=} {--email=}';

    protected $description = 'Create the company and its first owner account (run once per installation)';

    public function handle(TenantInstaller $installer): int
    {
        if (! config('erp.audit_key')) {
            $this->error('ERP_AUDIT_KEY is missing from .env. Run: php artisan erp:audit-key');

            return self::FAILURE;
        }
        if (Tenant::exists() && ! $this->confirm('A company already exists. Create another one (multi-company)?', false)) {
            return self::SUCCESS;
        }

        $company = $this->option('company') ?: text('Company name', required: true);
        $name = $this->option('name') ?: text('Owner full name', required: true);
        $username = $this->option('username') ?: text('Owner username (for POS sign-in)', required: true);
        $email = $this->option('email') ?: text('Owner email', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email');
        $pass = password('Owner password (12+ characters)', required: true);

        try {
            [$tenant, $user] = $installer->install($company, compact('name', 'username', 'email') + ['password' => $pass]);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created {$tenant->name} and owner {$user->email}.");
        $this->line('Next: sign in at '.url('/admin').' — you will be asked to set up two-factor sign-in, then the setup wizard opens.');

        return self::SUCCESS;
    }
}
