<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AuditKeyCommand extends Command
{
    protected $signature = 'erp:audit-key';

    protected $description = 'Write a new ERP_AUDIT_KEY to .env (only when none exists)';

    public function handle(): int
    {
        $env = base_path('.env');
        $contents = file_exists($env) ? file_get_contents($env) : '';
        // [^\r\n]: a Windows-edited .env has CRLF line endings, and "\r" is not a key.
        if (preg_match('/^ERP_AUDIT_KEY=[^\r\n]+/m', $contents)) {
            // Replacing the key would make every existing audit row fail verification.
            $this->warn('ERP_AUDIT_KEY already set; not replacing it.');

            return self::SUCCESS;
        }
        $key = 'base64:'.base64_encode(random_bytes(32));
        $contents = preg_match('/^ERP_AUDIT_KEY=(?=\r?$)/m', $contents)
            ? preg_replace('/^ERP_AUDIT_KEY=(?=\r?$)/m', "ERP_AUDIT_KEY={$key}", $contents)
            : rtrim($contents)."\nERP_AUDIT_KEY={$key}\n";
        file_put_contents($env, $contents);
        $this->info('ERP_AUDIT_KEY written to .env. Back it up with your other secrets: without it the audit log cannot be verified.');

        return self::SUCCESS;
    }
}
