<?php

namespace App\Domain\Service;

use App\Domain\Customers\CustomerService;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MaintenanceAsset;
use App\Models\ServiceLevel;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WhatsappOrder;
use App\Security\Authorizer;
use App\Support\NotifyPrefs;
use App\Support\Settings;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * The service desk (paid add-on "service"; interface stage 4).
 *
 * Tickets come from customers — WhatsApp (an open ticket takes their next messages), e-mail (through the provider's
 * forwarding; a reply quoting the ticket number joins it), the web form — and from staff: for a customer at the
 * counter or on the phone, or for themselves (a staff request). Each ticket has a deadline to answer and to fix by its
 * priority (service_levels, around the clock); while it waits for the customer the clock stops. People are told when a
 * deadline is near and managers when it is missed. Returns and warranty claims are checked against the sale (the
 * product's warranty months) or the asset's warranty. Replies go out by WhatsApp, SMS or e-mail through the message log.
 */
final class ServiceDesk
{
    public const ADDON = 'service';

    /** Share of the time gone at which the person handling it is warned. */
    public const WARN_AT = 0.75;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly LicenseManager $license,
        private readonly Notifier $notifier,
    ) {}

    public static function enabled(): bool
    {
        return app(LicenseManager::class)->hasAddon(self::ADDON);
    }

    /** The deadlines a company starts with (missing ones only). */
    public static function seedLevels(Tenant $tenant): void
    {
        foreach (ServiceLevel::DEFAULTS as $priority => [, $respond, $resolve]) {
            if (! ServiceLevel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('priority', $priority)->exists()) {
                (new ServiceLevel)->forceFill(['tenant_id' => $tenant->id, 'priority' => $priority, 'respond_minutes' => $respond, 'resolve_minutes' => $resolve])->save();
            }
        }
    }

    public function saveLevel(Actor $actor, ServiceLevel $level, int $respond, int $resolve): ServiceLevel
    {
        $this->requireAnywhere($actor, 'helpdesk.manage');
        if ($respond <= 0 || $resolve < $respond) {
            throw new DomainException('Give a time to answer, and a time to fix that is not shorter.');
        }
        $level->forceFill(['respond_minutes' => $respond, 'resolve_minutes' => $resolve]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $level, 'service_level.saved', $level->priority));
    }

    // --- opening ---------------------------------------------------------------------------------------------------------

    /**
     * Open a ticket. Staff: for a customer (kind other than internal) or a staff request (internal). The customer is
     * found by phone when not chosen.
     */
    public function open(Actor $actor, array $d): Ticket
    {
        $this->assertLicensed();
        $internal = ($d['kind'] ?? '') === 'internal';
        // Staff handling tickets open any; others may only ask for themselves (a staff request). The web form, e-mail and
        // WhatsApp open them with a system actor.
        if ($actor->user && ! $this->authz->allowsAnywhere($actor->user, 'helpdesk.work') && ! ($internal && $this->authz->allowsAnywhere($actor->user, 'helpdesk.raise'))) {
            throw new AccessDenied;
        }
        $subject = trim((string) ($d['subject'] ?? ''));
        if ($subject === '') {
            throw new DomainException('Say in a few words what it is about.');
        }
        $kind = isset(Ticket::KINDS[$d['kind'] ?? '']) ? $d['kind'] : 'support';
        $priority = isset(Ticket::PRIORITIES[$d['priority'] ?? '']) ? $d['priority'] : 'normal';
        $phone = ($d['contact_phone'] ?? null) ? CustomerService::normalisePhone((string) $d['contact_phone']) : null;
        $email = ($d['contact_email'] ?? null) ? strtolower(trim((string) $d['contact_email'])) : null;
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('That e-mail address is not valid.');
        }
        $customer = ($d['customer_id'] ?? null) ? Customer::find($d['customer_id']) : ($phone ? Customer::where('phone', $phone)->first() : null);
        if (! $internal && ! $customer && ! $phone && ! $email) {
            throw new DomainException('Choose the customer, or give a phone number or e-mail address to answer them.');
        }
        $asset = ($d['asset_id'] ?? null) ? MaintenanceAsset::find($d['asset_id']) : null;
        $branch = ($d['branch_id'] ?? null) ? Branch::find($d['branch_id']) : ($asset?->branch ?? ($customer?->home_branch_id ? Branch::find($customer->home_branch_id) : null));
        $assignee = ($d['assigned_to'] ?? null) ? User::find($d['assigned_to']) : null;
        if ($assignee && ! $this->authz->allowsAnywhere($assignee, 'helpdesk.work')) {
            throw new DomainException("{$assignee->name} does not handle tickets.");
        }
        $receipt = trim((string) ($d['receipt_no'] ?? '')) ?: null;
        $warranty = in_array($kind, ['warranty', 'return'], true) ? $this->warranty($receipt, $asset) : null;
        $now = CarbonImmutable::now();
        $level = ServiceLevel::where('priority', $priority)->first();

        return $this->recorder->transaction(function () use ($actor, $d, $subject, $kind, $priority, $phone, $email, $customer, $asset, $branch, $assignee, $receipt, $warranty, $now, $level, $internal) {
            $t = (new Ticket)->forceFill([
                'tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'ticket'), 'subject' => mb_substr($subject, 0, 255),
                'description' => $d['description'] ?? null, 'kind' => $kind, 'priority' => $priority, 'status' => $assignee ? 'open' : 'new',
                'source' => isset(Ticket::SOURCES[$d['source'] ?? '']) ? $d['source'] : ($internal ? 'staff' : 'counter'),
                'customer_id' => $customer?->id, 'contact_name' => mb_substr((string) ($d['contact_name'] ?? $customer?->contact_name ?? $customer?->name ?? ($internal ? $actor->name : '')), 0, 120) ?: null,
                'contact_phone' => $phone ?? $customer?->phone, 'contact_email' => $email ?? $customer?->email, 'branch_id' => $branch?->id, 'assigned_to' => $assignee?->id,
                'asset_id' => $asset?->id, 'receipt_no' => $receipt, 'under_warranty' => $warranty,
                'raised_by' => $actor->user?->id, 'raised_by_name' => $actor->name,
                'respond_by' => $level ? $now->addMinutes($level->respond_minutes) : null, 'resolve_by' => $level ? $now->addMinutes($level->resolve_minutes) : null,
            ]);
            $this->recorder->save($actor, $t, 'ticket.opened', branchId: $branch?->id);
            if (trim((string) ($d['description'] ?? '')) !== '') {
                $this->addMessage($t, $internal || ($d['source'] ?? '') === 'staff' ? 'note' : 'in', $d['source'] ?? 'counter', (string) $d['description'], $actor->name, $d['files'] ?? null);
            }
            $this->announce($t, $assignee ? "Ticket {$t->number} for you: {$t->subject}" : "New ticket {$t->number}: {$t->subject}");

            return $t;
        });
    }

    /** A customer's message from the web form. */
    public function fromWeb(Tenant $tenant, array $d): Ticket
    {
        return $this->open(Actor::system($tenant->id, trim((string) ($d['contact_name'] ?? '')) ?: 'Web form', 'web'), ['source' => 'web', 'kind' => $d['kind'] ?? 'support'] + $d);
    }

    /** An e-mail forwarded by the provider: a reply quoting a ticket number joins it; anything else opens one. */
    public function fromEmail(Tenant $tenant, string $from, ?string $name, string $subject, string $body): Ticket
    {
        app(TenantContext::class)->set($tenant->id);
        $from = strtolower(trim($from));
        if (preg_match('/\b(TCK-[A-Z0-9-]+)\b/i', $subject, $m) && ($t = Ticket::where('number', strtoupper($m[1]))->first())
            && $t->contact_email && strcasecmp($t->contact_email, $from) === 0) {
            $this->customerSaid($t, 'email', $body, $name ?: $from);

            return $t;
        }

        return $this->open(Actor::system($tenant->id, $name ?: $from, 'email'), ['source' => 'email', 'subject' => trim($subject) ?: '(no subject)', 'description' => $body,
            'contact_email' => $from, 'contact_name' => $name]);
    }

    /** WhatsApp: a customer with a ticket open on WhatsApp (busy in the last week) — their message joins it. */
    public function absorbWhatsapp(string $tenantId, string $phone, string $text): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $phone = CustomerService::normalisePhone($phone);
        $t = Ticket::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('contact_phone', $phone)->where('source', 'whatsapp')
            ->whereIn('status', ['new', 'open', 'waiting', 'resolved'])->where('updated_at', '>=', now()->subDays(7))->latest('updated_at')->first();
        if (! $t || trim($text) === '') {
            return false;
        }
        $this->customerSaid($t, 'whatsapp', $text, $t->contact_name ?: $phone);

        return true;
    }

    /** Turn a WhatsApp entry (Online → WhatsApp orders) into a ticket: the customer wrote about a problem, not an order. */
    public function fromWhatsappOrder(Actor $actor, WhatsappOrder $o, array $d): Ticket
    {
        return $this->recorder->transaction(function () use ($actor, $o, $d) {
            $t = $this->open($actor, $d + ['source' => 'whatsapp', 'subject' => mb_substr(strtok((string) $o->body, "\n") ?: 'WhatsApp message', 0, 120),
                'description' => $o->body, 'contact_phone' => '+'.ltrim($o->from_phone, '+'), 'contact_name' => $o->from_name, 'customer_id' => $o->customer_id]);
            $o->forceFill(['status' => 'declined', 'decline_reason' => "Moved to ticket {$t->number}"])->save();
            $this->recorder->audit->record($actor, 'whatsapp_order.to_ticket', $o, ['ticket' => [null, $t->number]]);

            return $t;
        });
    }

    // --- working a ticket ------------------------------------------------------------------------------------------------

    /** Answer the customer (WhatsApp, SMS or e-mail); with $wait the clock stops until they reply. */
    public function reply(Actor $actor, Ticket $t, string $body, string $channel, bool $wait = false): TicketMessage
    {
        $this->requireWork($actor, $t);
        if (trim($body) === '') {
            throw new DomainException('Write the reply.');
        }
        $to = match ($channel) {
            'email' => $t->contact_email, 'whatsapp', 'sms' => $t->contact_phone, default => null
        };
        if (! $to) {
            throw new DomainException('There is no '.($channel === 'email' ? 'e-mail address' : 'phone number').' to answer on.');
        }

        return $this->recorder->transaction(function () use ($actor, $t, $body, $channel, $wait, $to) {
            $text = $channel === 'email' ? $body : mb_substr($body, 0, 1000);
            $msg = app(Messenger::class)->send($t->tenant_id, $channel, $to, $text, $channel === 'email' ? "Re: {$t->subject} [{$t->number}]" : null, $t->number, $actor->name,
                $channel === 'whatsapp' ? Messenger::REPLY : 'ticket_reply');
            $m = $this->addMessage($t, 'out', $channel, $body, $actor->name, null, $msg->id);
            $t->first_response_at ??= now();
            if ($wait) {
                $t->forceFill(['status' => 'waiting', 'paused_at' => now()]);
            } elseif ($t->status === 'new') {
                $t->status = 'open';
            }
            $this->recorder->save($actor, $t, 'ticket.replied', $channel);

            return $m;
        });
    }

    public function note(Actor $actor, Ticket $t, string $body): TicketMessage
    {
        $this->requireWork($actor, $t, raiser: true);
        if (trim($body) === '') {
            throw new DomainException('Write the note.');
        }

        return $this->recorder->transaction(fn () => $this->addMessage($t, 'note', null, $body, $actor->name));
    }

    /** Give it to someone, and / or change its priority (deadlines follow, from when it was opened). */
    public function assign(Actor $actor, Ticket $t, ?string $userId, ?string $priority = null): Ticket
    {
        $this->requireWork($actor, $t);
        $user = $userId ? User::find($userId) : null;
        if ($userId && (! $user || ! $this->authz->allowsAnywhere($user, 'helpdesk.work'))) {
            throw new DomainException('Give it to someone who handles tickets.');
        }
        if ($t->assigned_to !== $userId && $t->assigned_to !== $actor->id && $userId !== $actor->id && ! $this->authz->allowsAnywhere($actor->user, 'helpdesk.manage')) {
            throw new AccessDenied('Passing on someone else’s ticket needs the service desk manager right.');
        }

        return $this->recorder->transaction(function () use ($actor, $t, $user, $priority) {
            $before = $t->assigned_to;
            $t->assigned_to = $user?->id;
            if ($t->status === 'new' && $user) {
                $t->status = 'open';
            }
            if ($priority && isset(Ticket::PRIORITIES[$priority]) && $priority !== $t->priority && ($level = ServiceLevel::where('priority', $priority)->first())) {
                $start = CarbonImmutable::parse($t->created_at);
                $t->forceFill(['priority' => $priority, 'respond_by' => $start->addMinutes($level->respond_minutes), 'resolve_by' => $start->addMinutes($level->resolve_minutes),
                    'warned_at' => null, 'escalated_at' => null]);
            }
            $this->recorder->save($actor, $t, 'ticket.assigned', $user?->name);
            if ($user && $user->id !== $before && $user->id !== $actor->id) {
                $this->tell($user, "Ticket {$t->number} for you: {$t->subject}", Ticket::PRIORITIES[$t->priority].' priority', $t);
            }

            return $t;
        });
    }

    public function resolve(Actor $actor, Ticket $t, string $resolution, ?string $tellBy = null): Ticket
    {
        $this->requireWork($actor, $t);
        if (! $t->isOpen()) {
            throw new DomainException('This ticket is already resolved.');
        }
        if (trim($resolution) === '') {
            throw new DomainException('Say how it was resolved.');
        }

        return $this->recorder->transaction(function () use ($actor, $t, $resolution, $tellBy) {
            $this->resume($t);
            $t->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolution' => mb_substr(trim($resolution), 0, 500)]);
            $t->first_response_at ??= now();
            $this->recorder->save($actor, $t, 'ticket.resolved', $resolution);
            if ($tellBy && $t->kind !== 'internal') { // with a link to say how it went
                $rate = URL::signedRoute('ticket.rate', ['ticket' => $t->id], now()->addDays(30));
                $this->reply($actor, $t, "{$resolution}\n\nHow did we do? {$rate}", $tellBy);
                $t->status = 'resolved';
                $t->save();
            }
            if ($t->kind === 'internal' && $t->raised_by && $t->raised_by !== $actor->id && ($u = User::find($t->raised_by))) {
                $this->tell($u, "Your request {$t->number} is resolved", $resolution, $t);
            }

            return $t;
        });
    }

    public function reopen(Actor $actor, Ticket $t): Ticket
    {
        $this->requireWork($actor, $t, raiser: true);
        if ($t->isOpen()) {
            return $t;
        }
        $t->forceFill(['status' => 'open', 'resolved_at' => null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $t, 'ticket.reopened'));
    }

    /** A customer's answer (WhatsApp, e-mail): joins the conversation, restarts the clock, reopens a resolved ticket. */
    public function customerSaid(Ticket $t, string $channel, string $body, string $who): TicketMessage
    {
        return $this->recorder->transaction(function () use ($t, $channel, $body, $who) {
            $m = $this->addMessage($t, 'in', $channel, $body, $who);
            $this->resume($t);
            if (in_array($t->status, ['waiting', 'resolved'], true)) {
                $t->forceFill(['status' => 'open', 'resolved_at' => null]);
            }
            $t->touch();
            $t->save();
            $this->recorder->audit->record(Actor::system($t->tenant_id, $who, $channel), 'ticket.customer_replied', $t, ['channel' => [null, $channel]]);
            if ($t->assigned_to && ($u = User::withoutGlobalScopes()->find($t->assigned_to))) {
                $this->tell($u, "{$t->number}: {$who} replied", mb_strimwidth($body, 0, 120, '…'), $t);
            }

            return $m;
        });
    }

    /** The customer's 1–5 from the link in the resolution message. */
    public function rate(Ticket $t, int $rating): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new DomainException('Choose from 1 to 5.');
        }
        $t->forceFill(['rating' => $rating])->save();
        $this->recorder->audit->record(Actor::system($t->tenant_id, 'The customer', 'web'), 'ticket.rated', $t, ['rating' => [null, $rating]]);
    }

    // --- deadlines -------------------------------------------------------------------------------------------------------

    /** Every few minutes: warn when a deadline is near, tell managers when one is missed, close old resolved tickets. */
    public function watch(string $tenantId): array
    {
        $warned = $escalated = $closed = 0;
        foreach (Ticket::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('status', ['new', 'open'])->whereNull('escalated_at')->get() as $t) {
            $deadline = $t->deadline();
            if (! $deadline) {
                continue;
            }
            $span = max(1, $t->created_at->diffInSeconds($deadline));
            if ($deadline->isPast()) {
                $this->notifier->toPermission($tenantId, 'helpdesk.manage', $t->branch_id, "Ticket {$t->number} is late: {$t->subject}",
                    ($t->first_response_at ? 'Not fixed' : 'Not answered').' by '.$deadline->format('j M H:i').($t->assigned_to ? '' : ' — nobody has it.'), $this->url($t), topic: 'service');
                $t->forceFill(['escalated_at' => now()])->save();
                $escalated++;
            } elseif (! $t->warned_at && now()->diffInSeconds($deadline) <= $span * (1 - self::WARN_AT)) {
                $this->announce($t, "Ticket {$t->number} is due ".$deadline->diffForHumans().": {$t->subject}");
                $t->forceFill(['warned_at' => now()])->save();
                $warned++;
            }
        }
        $days = (int) app(Settings::class)->get('service.close_after_days', $tenantId);
        $closed = Ticket::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'resolved')->where('resolved_at', '<=', now()->subDays(max(1, $days)))
            ->update(['status' => 'closed', 'updated_at' => now()]);

        return ['warned' => $warned, 'escalated' => $escalated, 'closed' => $closed];
    }

    // --- warranty --------------------------------------------------------------------------------------------------------

    /** Under warranty? From the sale (its products' warranty months) or the asset; null when it cannot be told. */
    public function warranty(?string $receiptNo, ?MaintenanceAsset $asset): ?bool
    {
        if ($asset && ($asset->warranty_until || $asset->contract_until)) {
            return $asset->underWarranty() || $asset->underContract();
        }
        if (! $receiptNo) {
            return null;
        }
        $sale = DB::table('sales')->where('receipt_no', $receiptNo)->first(['id', 'occurred_at']);
        if (! $sale) {
            throw new DomainException("No sale with receipt number {$receiptNo}.");
        }
        $months = (int) DB::table('sale_lines')->join('products', 'products.id', '=', 'sale_lines.product_id')->where('sale_lines.sale_id', $sale->id)->max('products.warranty_months');

        return $months > 0 && CarbonImmutable::parse($sale->occurred_at)->addMonths($months)->endOfDay()->isFuture();
    }

    // --- helpers ---------------------------------------------------------------------------------------------------------

    private function addMessage(Ticket $t, string $direction, ?string $channel, string $body, ?string $author, ?array $files = null, ?string $messageId = null): TicketMessage
    {
        return TicketMessage::create(['tenant_id' => $t->tenant_id, 'ticket_id' => $t->id, 'direction' => $direction, 'channel' => $channel, 'body' => $body,
            'files' => $files, 'message_id' => $messageId, 'author_name' => $author ? mb_substr($author, 0, 120) : null]);
    }

    /** The clock starts again: deadlines move on by the time it waited. */
    private function resume(Ticket $t): void
    {
        if (! $t->paused_at) {
            return;
        }
        $paused = (int) $t->paused_at->diffInSeconds(now());
        $t->forceFill(['respond_by' => $t->respond_by?->addSeconds($paused), 'resolve_by' => $t->resolve_by?->addSeconds($paused), 'paused_at' => null]);
    }

    /** The person it is given to, else everyone who handles tickets (of its branch). */
    private function announce(Ticket $t, string $title): void
    {
        if ($t->assigned_to && ($u = User::withoutGlobalScopes()->find($t->assigned_to))) {
            $this->tell($u, $title, Ticket::PRIORITIES[$t->priority].' priority', $t);

            return;
        }
        $this->notifier->toPermission($t->tenant_id, 'helpdesk.work', $t->branch_id, $title, Ticket::PRIORITIES[$t->priority].' priority', $this->url($t),
            [$t->raised_by], 'service');
    }

    private function tell(User $u, string $title, ?string $body, Ticket $t): void
    {
        if ($u->is_active && NotifyPrefs::wants($u, 'service')) {
            Notification::make()->title($title)->body($body)->actions([Action::make('open')->label('Open')->url($this->url($t))->markAsRead()])->sendToDatabase($u);
        }
    }

    private function url(Ticket $t): string
    {
        return url('/admin/tickets/'.$t->id);
    }

    private function requireWork(Actor $actor, Ticket $t, bool $raiser = false): void
    {
        $this->assertLicensed();
        if (! $actor->user) {
            throw new AccessDenied;
        }
        if ($raiser && $t->raised_by === $actor->id && $t->kind === 'internal') {
            return; // the person who asked may add to their own request
        }
        $branch = $t->branch_id ? Branch::find($t->branch_id) : null;
        if (! ($branch ? $this->authz->allows($actor->user, 'helpdesk.work', $branch) : $this->authz->allowsAnywhere($actor->user, 'helpdesk.work'))) {
            throw new AccessDenied;
        }
    }

    private function requireAnywhere(Actor $actor, string $permission): void
    {
        $this->assertLicensed();
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }

    public function assertLicensed(): void
    {
        if (! $this->license->hasAddon(self::ADDON)) {
            throw new DomainException('The service desk is a paid add-on: ask SyncLytics to add it to your licence.');
        }
    }
}
