<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Watermark;
use Illuminate\Console\Command;

/** Read the hidden licence/build reference out of text copied from a document. */
class LicenseTraceCommand extends Command
{
    protected $signature = 'license:trace {file : a text or HTML file containing text copied from the document}';

    protected $description = 'Reveal the hidden licence and build reference in copied text';

    public function handle(): int
    {
        $found = Watermark::reveal((string) @file_get_contents($this->argument('file')));
        if (! $found) {
            $this->warn('No hidden reference found.');

            return self::FAILURE;
        }
        foreach (array_unique($found) as $ref) {
            $this->info("Reference: {$ref}  (licence short id · build id)");
        }

        return self::SUCCESS;
    }
}
