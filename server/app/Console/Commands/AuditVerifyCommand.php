<?php

namespace App\Console\Commands;

use App\Domain\Support\AuditVerifier;
use App\Models\Tenant;
use Illuminate\Console\Command;

class AuditVerifyCommand extends Command
{
    protected $signature = 'erp:audit-verify';

    protected $description = 'Verify the tamper-evident audit chain for every company';

    public function handle(AuditVerifier $verifier): int
    {
        $ok = true;
        foreach (Tenant::all() as $tenant) {
            $r = $verifier->verify($tenant->id);
            if ($r['ok']) {
                $this->info("{$tenant->name}: OK ({$r['checked']} rows)");
            } else {
                $ok = false;
                $this->error("{$tenant->name}: FAILED at row {$r['first_bad_seq']} — {$r['problem']}");
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
