<?php

namespace App\Domain\Integrations;

use App\Filament\Pages\AccountingExportPage;
use App\Filament\Pages\Backups;
use App\Filament\Pages\Copilot;
use App\Filament\Pages\MessagingSetup;
use App\Filament\Resources\ApiClients\ApiClientResource;
use App\Filament\Resources\FiscalSubmissions\FiscalSubmissionResource;
use App\Filament\Resources\MomoCharges\MomoChargeResource;
use App\Filament\Resources\SalesChannels\SalesChannelResource;
use App\Filament\Resources\SyncPeers\SyncPeerResource;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every connection to the outside world on one page (Setup → Integrations; paid add-on "integrations"): whether it is
 * set up, when it last worked, what failed lately, and where to set it up or test it. Read only: it looks at what the
 * connections already record (messages sent, GRA submissions, mobile money charges, online shop jobs, API use, branch
 * servers, off-server backups, AI requests); nothing is stored.
 */
final class IntegrationsOverview
{
    /** ok = set up and working; attention = set up but failing lately; off = not set up (or switched off). */
    public const STATES = ['ok' => 'Working', 'attention' => 'Needs attention', 'off' => 'Not set up'];

    public function __construct(private readonly Settings $settings) {}

    /** @return list<array{key: string, title: string, what: string, state: string, detail: string, last_ok: ?CarbonImmutable, failed: int, url: ?string, link: string}> */
    public function cards(string $tenantId): array
    {
        $since = now()->subDays(7);
        $cards = [];
        foreach (['email' => ['E-mail', 'Invoices, statements, receipts, alerts and the daily summary by e-mail.'],
            'sms' => ['SMS', 'Payment alerts, refill reminders and customer messages by SMS.'],
            'whatsapp' => ['WhatsApp', 'Messages, receipts and orders by WhatsApp Business.']] as $channel => [$title, $what]) {
            $provider = DB::table('message_providers')->where('tenant_id', $tenantId)->where('channel', $channel)->where('is_active', true)->first(['provider']);
            $set = $provider !== null || ($channel === 'email' && ! in_array(config('mail.default'), ['log', 'array', null], true));
            $sent = fn () => DB::table('messages')->where('tenant_id', $tenantId)->where('channel', $channel);
            $cards[] = $this->card($channel, $title, $what, $set,
                $set ? ($provider ? 'Through '.ucfirst((string) $provider->provider) : 'Through the server\'s mail settings') : 'No provider set up yet',
                $this->date($sent()->where('status', 'sent')->max('sent_at')), $sent()->where('status', 'failed')->where('created_at', '>=', $since)->count(),
                $this->url(MessagingSetup::class), 'Set up and send a test');
        }

        $momoMode = (string) $this->setting('momo.confirm', $tenantId, 'manual');
        $gateway = DB::table('momo_gateways')->where('tenant_id', $tenantId)->where('is_active', true)->value('provider');
        $charges = fn () => DB::table('momo_charges')->where('tenant_id', $tenantId);
        $cards[] = $this->card('momo', 'Mobile money', 'Payment requests sent to the customer\'s phone, confirmed by the network.', $momoMode !== 'manual' && $gateway,
            $gateway ? 'Through '.ucfirst((string) $gateway) : 'Cashiers type the transaction ID (no provider)', $this->date($charges()->where('status', 'success')->max('paid_at')),
            $charges()->where('status', 'failed')->where('created_at', '>=', $since)->count(), $this->url(MomoChargeResource::class), 'See payment requests');

        $evat = (string) $this->setting('evat.mode', $tenantId, 'off');
        $subs = fn () => DB::table('fiscal_submissions')->where('tenant_id', $tenantId);
        $cards[] = $this->card('evat', 'GRA E-VAT', 'Every sale and invoice reported to GRA, with its QR code.', $evat !== 'off',
            $evat === 'off' ? 'Switched off (System → Settings → GRA E-VAT)' : 'Mode: '.$evat.' · '.$subs()->where('status', 'pending')->count().' waiting to send',
            $this->date($subs()->where('status', 'submitted')->max('updated_at')), $subs()->whereIn('status', ['failed', 'rejected'])->where('updated_at', '>=', $since)->count(),
            $this->url(FiscalSubmissionResource::class), 'See submissions');

        $shops = DB::table('sales_channels')->where('tenant_id', $tenantId)->where('status', 'active');
        $cards[] = $this->card('channels', 'Online shops', 'Your own online shop and marketplaces: products, stock and orders kept in step.', (clone $shops)->exists(),
            (clone $shops)->count().' active', $this->date((clone $shops)->max('last_reconciled_at')),
            DB::table('channel_jobs')->where('tenant_id', $tenantId)->where('status', 'failed')->count(), $this->url(SalesChannelResource::class), 'Set up');

        $api = DB::table('api_clients')->where('tenant_id', $tenantId)->where('is_active', true);
        $cards[] = $this->card('api', 'Other systems (API)', 'Programs that read or send data through the API, and webhooks.', (clone $api)->exists(),
            (clone $api)->count().' connected system(s)', $this->date((clone $api)->max('last_used_at')),
            (int) DB::table('webhook_endpoints')->where('tenant_id', $tenantId)->where('is_active', true)->sum('failures'), $this->url(ApiClientResource::class), 'Set up');

        $peers = DB::table('sync_peers')->where('tenant_id', $tenantId)->where('is_active', true);
        $cards[] = $this->card('sync', 'Branch servers', 'Branch servers sending and receiving the company\'s data.', (clone $peers)->exists(),
            (clone $peers)->count().' server(s)', $this->date((clone $peers)->max('last_success_at')),
            (clone $peers)->whereNotNull('last_error')->whereColumn('last_attempt_at', '>', 'last_success_at')->count(), $this->url(SyncPeerResource::class), 'Set up');

        $dest = DB::table('backup_destinations')->where('is_active', true);
        $cards[] = $this->card('backups', 'Backups off the server', 'Encrypted copies of the data kept somewhere else, in case the server is lost.', (clone $dest)->exists(),
            (clone $dest)->count().' place(s)', $this->date((clone $dest)->max('last_ok_at')),
            (clone $dest)->whereNotNull('last_error')->count(), $this->url(Backups::class), 'Set up and test');

        $ai = (string) $this->setting('ai.provider', $tenantId, 'off');
        $req = fn () => DB::table('ai_requests')->where('tenant_id', $tenantId);
        $cards[] = $this->card('ai', 'AI assistant', 'Insights, invoice reading and questions about your business.', $ai !== 'off',
            $ai === 'off' ? 'Off — built-in insights only' : 'Provider: '.$ai, $this->date($req()->where('status', 'ok')->max('created_at')),
            $req()->where('status', 'error')->where('created_at', '>=', $since)->count(), $this->url(Copilot::class), 'Open');

        $cards[] = $this->card('accounting', 'Accounting programs', 'QuickBooks, Xero or Sage: journals exported as a file when you need them.', true,
            'Export on demand', null, 0, $this->url(AccountingExportPage::class), 'Export', always: true);

        return $cards;
    }

    private function card(string $key, string $title, string $what, bool $set, string $detail, ?CarbonImmutable $lastOk, int $failed, ?string $url, string $link, bool $always = false): array
    {
        $state = ! $set ? 'off' : ($failed > 0 ? 'attention' : 'ok');

        return compact('key', 'title', 'what', 'state', 'detail', 'failed', 'url', 'link') + ['last_ok' => $lastOk, 'always' => $always];
    }

    private function date(mixed $v): ?CarbonImmutable
    {
        return $v ? CarbonImmutable::parse($v) : null;
    }

    private function setting(string $key, string $tenantId, string $fallback): mixed
    {
        try {
            return $this->settings->get($key, $tenantId);
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** The set-up page's address when the person may open it. */
    private function url(string $class): ?string
    {
        try {
            $ok = method_exists($class, 'canViewAny') ? $class::canViewAny() : $class::canAccess();

            return $ok ? $class::getUrl(method_exists($class, 'canViewAny') ? 'index' : []) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
