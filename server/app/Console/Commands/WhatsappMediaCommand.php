<?php

namespace App\Console\Commands;

use App\Domain\Channels\WhatsappOrders;
use Illuminate\Console\Command;

/** WhatsApp orders: photos and documents not fetched when they arrived (Meta or the internet was slow), tried again. */
class WhatsappMediaCommand extends Command
{
    protected $signature = 'erp:whatsapp-media';

    protected $description = 'Fetch the photos of WhatsApp orders not fetched yet';

    public function handle(WhatsappOrders $orders): int
    {
        $this->info($orders->fetchPending().' file(s) fetched.');

        return self::SUCCESS;
    }
}
