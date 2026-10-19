<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Messenger;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\VendorLicense;
use App\Support\TenantContext;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Licence server only: tell customers (e-mail, WhatsApp) and SyncLytics staff 60, 30 and 7 days before a licence ends, and when it has ended. */
class VendorLicenseRemindersCommand extends Command
{
    protected $signature = 'vendor:license-reminders';

    protected $description = 'Licence server: renewal reminders to customers and SyncLytics staff';

    public const STEPS = [60, 30, 7];

    public function handle(Messenger $messenger): int
    {
        if (! config('license.server.enabled')) {
            $this->info('Not the licence server: nothing to do.');

            return self::SUCCESS;
        }
        $tenant = Tenant::orderBy('created_at')->first();
        if (! $tenant) {
            return self::SUCCESS;
        }
        app(TenantContext::class)->set($tenant->id);
        $staff = User::where('is_active', true)->where('security_level', '>=', 6)->get();
        $sent = 0;
        $live = VendorLicense::with('client')->whereNull('revoked_at')->whereNull('superseded_by')->whereIn('kind', ['paid', 'trial'])->whereNotNull('expires_on')
            ->where('expires_on', '<=', now()->addDays(self::STEPS[0])->toDateString())->get();
        foreach ($live as $lic) {
            $days = $lic->daysLeft();
            $kind = $days < 0 ? 'expired' : (string) collect(self::STEPS)->sortDesc()->last(fn ($s) => $days <= $s);
            if (DB::table('vendor_reminders')->where('license_id', $lic->id)->where('kind', $kind)->exists()) {
                continue;
            }
            $who = $lic->client?->name ?? $lic->payload['customer'] ?? 'Customer';
            $when = $lic->expires_on->format('j M Y');
            $text = $days < 0
                ? "Dear {$who}, your SES Labs licence ({$lic->short()}) ended on {$when}. The system will become read-only after the grace period. Please contact SyncLytics to renew."
                : "Dear {$who}, your SES Labs licence ({$lic->short()}) ends on {$when} ({$days} days). Please contact SyncLytics to renew so your system keeps working without interruption.";
            $channels = [];
            foreach (['email' => $lic->client?->email, 'whatsapp' => $lic->client?->whatsapp] as $channel => $to) {
                if (! $to) {
                    continue;
                }
                try {
                    $messenger->send($tenant->id, $channel, $to, $text, 'SES Labs licence renewal', 'licence '.$lic->short(), 'Licence reminders');
                    $channels[] = $channel;
                } catch (DomainException $e) {
                    $this->warn("{$who}: {$e->getMessage()}");
                }
            }
            Notification::make()->title($days < 0 ? "Licence ended: {$who}" : "Licence ends in {$days} days: {$who}")
                ->body("{$lic->short()} · {$when}".($channels ? ' · customer told by '.implode(' and ', $channels) : ' · no e-mail or WhatsApp on file'))
                ->sendToDatabase($staff);
            $channels[] = 'staff';
            DB::table('vendor_reminders')->insert(['id' => (string) Str::uuid7(), 'license_id' => $lic->id, 'kind' => $kind, 'channels' => implode(',', $channels), 'sent_at' => now()]);
            $sent++;
        }
        $this->info("{$sent} reminder(s).");

        return self::SUCCESS;
    }
}
