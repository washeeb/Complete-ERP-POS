<?php

namespace App\Domain\Crm;

use App\Domain\Customers\CustomerService;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Phone;
use App\Domain\Sales\CustomerOrderService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\CrmActivity;
use App\Models\CrmCampaign;
use App\Models\CrmCampaignRecipient;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\NotifyPrefs;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * CRM (paid add-on "crm"; interface stage 2).
 *  - Leads: people or businesses who may buy, from a walk-in, a call, WhatsApp, the website… worked (contacted,
 *    interested) until they become a customer — with their consents and follow-ups — or are let go with a reason.
 *  - Deals: a sale being worked on, on a pipeline of stages with a chance of winning (the weighted pipeline). A deal
 *    makes its quotation (Quotes & orders); confirming that order wins the deal by itself.
 *  - Follow-ups: calls, meetings and tasks with a due time (the person it is given to is reminded) and notes of what
 *    happened, on a lead, a deal or a customer.
 *  - Campaigns: one message to many customers (and leads) by SMS, WhatsApp or e-mail — only to those who agreed to that
 *    channel; e-mails carry a link to stop them. Sent through the message log (retries, practice companies never send).
 * Records belong to a branch; people see and work those of the branches they have the CRM rights for.
 */
final class Crm
{
    public const ADDON = 'crm';

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly LicenseManager $license,
    ) {}

    public static function enabled(): bool
    {
        return app(LicenseManager::class)->hasAddon(self::ADDON);
    }

    // --- stages --------------------------------------------------------------------------------------------------------

    /** The stages a company starts with (missing ones only; renamed or removed ones are left as the company made them). */
    public static function seedStages(Tenant $tenant): void
    {
        if (CrmStage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }
        $sort = 0;
        foreach (CrmStage::DEFAULTS as $name => [$probability, $kind]) {
            (new CrmStage)->forceFill(['tenant_id' => $tenant->id, 'name' => $name, 'probability' => $probability, 'kind' => $kind, 'sort' => $sort += 10, 'is_active' => true])->save();
        }
    }

    public function saveStage(Actor $actor, CrmStage $stage, array $d): CrmStage
    {
        $this->requireAnywhere($actor, 'crm.setup');
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new DomainException('Name the stage.');
        }
        $kind = isset(CrmStage::KINDS[$d['kind'] ?? '']) ? $d['kind'] : 'open';
        $active = (bool) ($d['is_active'] ?? true);
        if ($stage->exists && $stage->kind !== $kind && CrmDeal::where('stage_id', $stage->id)->exists()) {
            throw new DomainException('Deals are at this stage: make a new stage instead of changing its kind.');
        }
        if ($stage->exists && ! $active && CrmDeal::where('stage_id', $stage->id)->where('status', 'open')->exists()) {
            throw new DomainException('Move the deals at this stage to another one first.');
        }
        $stage->forceFill(['tenant_id' => $actor->tenantId, 'name' => mb_substr($name, 0, 60), 'kind' => $kind,
            'probability' => $kind === 'won' ? 100 : ($kind === 'lost' ? 0 : max(0, min(100, (int) ($d['probability'] ?? 10)))),
            'sort' => (int) ($d['sort'] ?? ($stage->sort ?: (int) CrmStage::max('sort') + 10)), 'is_active' => $active]);
        if ($stage->is_active && in_array($kind, ['won', 'lost'], true)
            && CrmStage::where('kind', $kind)->where('is_active', true)->when($stage->exists, fn ($q) => $q->whereKeyNot($stage->id))->exists()) {
            throw new DomainException('There is already a '.strtolower(CrmStage::KINDS[$kind]).' stage.');
        }

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $stage, 'crm_stage.saved'));
    }

    /** @return Collection<int, CrmStage> */
    public function openStages(): Collection
    {
        return CrmStage::where('is_active', true)->where('kind', 'open')->orderBy('sort')->get();
    }

    private function stageOf(string $kind): CrmStage
    {
        return CrmStage::where('is_active', true)->where('kind', $kind)->orderBy('sort')->first()
            ?? throw new DomainException('Set up a '.strtolower(CrmStage::KINDS[$kind]).' stage first (CRM → Pipeline stages).');
    }

    // --- leads ---------------------------------------------------------------------------------------------------------

    public function saveLead(Actor $actor, CrmLead $lead, array $d): CrmLead
    {
        $branch = $this->branchFor($actor, $d['branch_id'] ?? $lead->branch_id, 'crm.manage');
        if ($lead->exists && ! $lead->isOpen()) {
            throw new DomainException('This lead is closed ('.CrmLead::STATUSES[$lead->status].').');
        }
        $name = trim((string) ($d['name'] ?? $lead->name));
        if ($name === '') {
            throw new DomainException('Enter the person’s name.');
        }
        $phone = array_key_exists('phone', $d) ? ($d['phone'] ? CustomerService::normalisePhone((string) $d['phone']) : null) : $lead->phone;
        $email = array_key_exists('email', $d) ? (trim((string) $d['email']) ?: null) : $lead->email;
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('That e-mail address is not valid.');
        }
        if (! $phone && ! $email) {
            throw new DomainException('Enter a phone number or an e-mail address, so you can follow up.');
        }
        $status = $d['status'] ?? $lead->status ?? 'new';
        if (! in_array($status, ['new', 'contacted', 'qualified'], true)) {
            throw new DomainException('Use “Make customer” or “Lost” to close a lead.');
        }

        return $this->recorder->transaction(function () use ($actor, $lead, $d, $branch, $name, $phone, $email, $status) {
            $isNew = ! $lead->exists;
            $lead->forceFill([
                'name' => mb_substr($name, 0, 255), 'company_name' => trim((string) ($d['company_name'] ?? $lead->company_name)) ?: null,
                'phone' => $phone, 'email' => $email, 'branch_id' => $branch->id, 'status' => $status,
                'source' => isset(CrmLead::SOURCES[$d['source'] ?? '']) ? $d['source'] : ($lead->source ?? 'other'),
                'interest' => array_key_exists('interest', $d) ? (trim((string) $d['interest']) ?: null) : $lead->interest,
                'value_minor' => array_key_exists('value_minor', $d) ? ($d['value_minor'] === null ? null : max(0, (int) $d['value_minor'])) : $lead->value_minor,
                'owner_id' => $this->ownerFor($d['owner_id'] ?? $lead->owner_id ?? $actor->id, $branch),
                'notes' => array_key_exists('notes', $d) ? $d['notes'] : $lead->notes,
                'consents' => $this->consents($actor, $lead->consents ?? [], (array) ($d['consents'] ?? [])),
            ]);
            if ($isNew) {
                $lead->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'crm_lead'), 'created_by_name' => $actor->name]);
            }
            $owner = $lead->getOriginal('owner_id');
            $saved = $this->recorder->save($actor, $lead, $isNew ? 'crm_lead.created' : 'crm_lead.updated', branchId: $branch->id);
            if ($lead->owner_id && $lead->owner_id !== $actor->id && ($isNew || $owner !== $lead->owner_id)) {
                $this->tell($lead->owner_id, "Lead {$lead->number} given to you: {$lead->name}", $lead->interest, url('/admin/crm-leads/'.$lead->id.'/edit'));
            }

            return $saved;
        });
    }

    /**
     * The lead becomes a customer (an existing one with the same phone number is used instead of a duplicate), keeping
     * their consents and follow-ups; with $dealTitle a deal is opened for them at the first stage.
     *
     * @return array{customer: Customer, deal: ?CrmDeal}
     */
    public function convertLead(Actor $actor, CrmLead $lead, ?string $dealTitle = null): array
    {
        $this->require($actor, 'crm.manage', $lead->branch);
        if (! $lead->isOpen()) {
            throw new DomainException('This lead is closed ('.CrmLead::STATUSES[$lead->status].').');
        }

        return $this->recorder->transaction(function () use ($actor, $lead, $dealTitle) {
            $customer = $lead->phone ? Customer::where('phone', $lead->phone)->first() : null;
            $customer ??= app(CustomerService::class)->save($actor, new Customer, [
                'type' => $lead->company_name ? 'business' : 'individual', 'name' => $lead->company_name ?: $lead->name,
                'contact_name' => $lead->company_name ? $lead->name : null, 'phone' => $lead->phone, 'email' => $lead->email,
                'home_branch_id' => $lead->branch_id, 'notes' => $lead->notes,
                'consents' => array_map(fn ($c) => (bool) ($c['granted'] ?? false), $lead->consents ?? []),
            ], 'CRM lead '.$lead->number);
            $lead->forceFill(['status' => 'converted', 'customer_id' => $customer->id]);
            $this->recorder->save($actor, $lead, 'crm_lead.converted', $customer->name, $lead->branch_id);
            CrmActivity::where('lead_id', $lead->id)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            CrmDeal::where('lead_id', $lead->id)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            $deal = $dealTitle !== null && trim($dealTitle) !== ''
                ? $this->saveDeal($actor, new CrmDeal, ['title' => $dealTitle, 'customer_id' => $customer->id, 'lead_id' => $lead->id, 'value_minor' => (int) ($lead->value_minor ?? 0),
                    'branch_id' => $lead->branch_id, 'owner_id' => $lead->owner_id])
                : null;

            return ['customer' => $customer, 'deal' => $deal];
        });
    }

    public function loseLead(Actor $actor, CrmLead $lead, string $reason): CrmLead
    {
        $this->require($actor, 'crm.manage', $lead->branch);
        if (! $lead->isOpen()) {
            throw new DomainException('This lead is already closed.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Say why the lead is lost (it helps see what to do better).');
        }

        return $this->recorder->transaction(function () use ($actor, $lead, $reason) {
            $lead->forceFill(['status' => 'lost', 'lost_reason' => mb_substr(trim($reason), 0, 255)]);
            CrmActivity::where('lead_id', $lead->id)->whereNull('done_at')->whereNotNull('due_at')->update(['done_at' => now(), 'outcome' => 'Lead lost']);

            return $this->recorder->save($actor, $lead, 'crm_lead.lost', $reason, $lead->branch_id);
        });
    }

    // --- deals ---------------------------------------------------------------------------------------------------------

    public function saveDeal(Actor $actor, CrmDeal $deal, array $d): CrmDeal
    {
        $branch = $this->branchFor($actor, $d['branch_id'] ?? $deal->branch_id, 'crm.manage');
        if ($deal->exists && $deal->status !== 'open') {
            throw new DomainException('This deal is closed: reopen it to change it.');
        }
        $title = trim((string) ($d['title'] ?? $deal->title));
        if ($title === '') {
            throw new DomainException('Give the deal a short title (what they want).');
        }
        $customerId = array_key_exists('customer_id', $d) ? ($d['customer_id'] ?: null) : $deal->customer_id;
        $leadId = array_key_exists('lead_id', $d) ? ($d['lead_id'] ?: null) : $deal->lead_id;
        if ($customerId && ! Customer::whereKey($customerId)->exists()) {
            throw new DomainException('Choose the customer.');
        }
        if ($leadId && ! CrmLead::whereKey($leadId)->exists()) {
            throw new DomainException('Choose the lead.');
        }
        if (! $customerId && ! $leadId) {
            throw new DomainException('Choose the customer or the lead the deal is with.');
        }
        $stage = isset($d['stage_id']) ? CrmStage::find($d['stage_id']) : ($deal->stage_id ? CrmStage::find($deal->stage_id) : $this->openStages()->first());
        if (! $stage || ! $stage->is_active || $stage->kind !== 'open') {
            throw new DomainException('Choose a stage in progress (won and lost are set with “Won” and “Lost”).');
        }

        return $this->recorder->transaction(function () use ($actor, $deal, $d, $branch, $title, $customerId, $leadId, $stage) {
            $isNew = ! $deal->exists;
            $deal->forceFill([
                'title' => mb_substr($title, 0, 255), 'customer_id' => $customerId, 'lead_id' => $leadId, 'stage_id' => $stage->id, 'branch_id' => $branch->id,
                'value_minor' => array_key_exists('value_minor', $d) ? max(0, (int) $d['value_minor']) : ($deal->value_minor ?? 0),
                'expected_close_on' => array_key_exists('expected_close_on', $d) ? ($d['expected_close_on'] ?: null) : $deal->expected_close_on,
                'owner_id' => $this->ownerFor($d['owner_id'] ?? $deal->owner_id ?? $actor->id, $branch),
                'notes' => array_key_exists('notes', $d) ? $d['notes'] : $deal->notes,
            ]);
            if ($isNew) {
                $deal->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'crm_deal'), 'status' => 'open',
                    'currency_code' => Tenant::find($actor->tenantId)->currency_code, 'created_by_name' => $actor->name]);
            }
            $owner = $deal->getOriginal('owner_id');
            $saved = $this->recorder->save($actor, $deal, $isNew ? 'crm_deal.created' : 'crm_deal.updated', branchId: $branch->id);
            $deal->unsetRelation('stage');
            if ($deal->owner_id && $deal->owner_id !== $actor->id && ($isNew || $owner !== $deal->owner_id)) {
                $this->tell($deal->owner_id, "Deal {$deal->number} given to you: {$deal->title}", $deal->contactName(), url('/admin/crm-deals/'.$deal->id.'/edit'));
            }

            return $saved;
        });
    }

    /** Drag on the pipeline: another stage in progress, or the won / lost stage. */
    public function moveDeal(Actor $actor, CrmDeal $deal, CrmStage $stage, ?string $lostReason = null): CrmDeal
    {
        return match ($stage->kind) {
            'won' => $this->winDeal($actor, $deal),
            'lost' => $this->loseDeal($actor, $deal, (string) $lostReason),
            default => $this->saveDeal($actor, $deal, ['stage_id' => $stage->id]),
        };
    }

    public function winDeal(Actor $actor, CrmDeal $deal, ?string $why = null): CrmDeal
    {
        $this->require($actor, 'crm.manage', $deal->branch);

        return $this->close($actor, $deal, 'won', $why);
    }

    public function loseDeal(Actor $actor, CrmDeal $deal, string $reason): CrmDeal
    {
        $this->require($actor, 'crm.manage', $deal->branch);
        if (trim($reason) === '') {
            throw new DomainException('Say why the deal is lost (price, went elsewhere, no longer needed…).');
        }

        return $this->close($actor, $deal, 'lost', $reason);
    }

    public function reopenDeal(Actor $actor, CrmDeal $deal): CrmDeal
    {
        $this->require($actor, 'crm.manage', $deal->branch);
        if ($deal->status === 'open') {
            return $deal;
        }
        $stage = $this->openStages()->last() ?? throw new DomainException('Set up a stage in progress first.');

        return $this->recorder->transaction(function () use ($actor, $deal, $stage) {
            $deal->forceFill(['status' => 'open', 'stage_id' => $stage->id, 'closed_at' => null, 'lost_reason' => null]);

            return $this->recorder->save($actor, $deal, 'crm_deal.reopened', branchId: $deal->branch_id);
        });
    }

    /**
     * The deal's quotation (Quotes & orders), started empty for its customer at a store of the deal's branch; the deal
     * moves to "Quotation sent" when there is such a stage. A deal with a lead only makes the lead a customer first.
     */
    public function quoteForDeal(Actor $actor, CrmDeal $deal): CustomerOrder
    {
        $this->require($actor, 'crm.manage', $deal->branch);
        if ($deal->status !== 'open') {
            throw new DomainException('This deal is closed.');
        }
        if ($deal->customer_order_id) {
            return $deal->order;
        }

        return $this->recorder->transaction(function () use ($actor, $deal) {
            if (! $deal->customer_id) {
                $lead = $deal->lead ?? throw new DomainException('Choose the customer the deal is with.');
                $deal->customer_id = $lead->customer_id ?? $this->convertLead($actor, $lead)['customer']->id;
            }
            $warehouse = Warehouse::where('branch_id', $deal->branch_id)->where('is_active', true)->orderBy('code')->first()
                ?? throw new DomainException('The deal’s branch has no store to sell from.');
            $order = app(CustomerOrderService::class)->saveQuotation($actor, new CustomerOrder, ['customer_id' => $deal->customer_id, 'warehouse_id' => $warehouse->id,
                'customer_ref' => $deal->number, 'note' => $deal->title]);
            $quoted = $this->openStages()->first(fn (CrmStage $s) => str_contains(strtolower($s->name), 'quot'));
            $deal->forceFill(['customer_order_id' => $order->id] + ($quoted ? ['stage_id' => $quoted->id] : []));
            $this->recorder->save($actor, $deal, 'crm_deal.quoted', $order->quote_number, $deal->branch_id);

            return $order;
        });
    }

    /** Called when a customer order is confirmed: the deal it came from is won. */
    public function orderConfirmed(Actor $actor, CustomerOrder $order): void
    {
        foreach (CrmDeal::where('customer_order_id', $order->id)->where('status', 'open')->get() as $deal) {
            $deal->value_minor = (int) $order->total_minor;
            $this->close($actor, $deal, 'won', 'order '.($order->number ?? $order->quote_number).' confirmed');
        }
    }

    private function close(Actor $actor, CrmDeal $deal, string $status, ?string $why): CrmDeal
    {
        if ($deal->status !== 'open') {
            throw new DomainException('This deal is already closed.');
        }
        $stage = $this->stageOf($status);

        return $this->recorder->transaction(function () use ($actor, $deal, $status, $why, $stage) {
            $deal->forceFill(['status' => $status, 'stage_id' => $stage->id, 'closed_at' => now(), 'lost_reason' => $status === 'lost' ? mb_substr(trim((string) $why), 0, 255) : null]);
            CrmActivity::where('deal_id', $deal->id)->whereNull('done_at')->whereNotNull('due_at')->update(['done_at' => now(), 'outcome' => 'Deal '.$status]);

            return $this->recorder->save($actor, $deal, 'crm_deal.'.$status, $why, $deal->branch_id);
        });
    }

    // --- follow-ups ----------------------------------------------------------------------------------------------------

    /** A follow-up (with due_at) or a note of what happened, on a lead, deal or customer. */
    public function logActivity(Actor $actor, array $d): CrmActivity
    {
        $lead = isset($d['lead_id']) ? CrmLead::find($d['lead_id']) : null;
        $deal = isset($d['deal_id']) ? CrmDeal::find($d['deal_id']) : null;
        $customer = isset($d['customer_id']) ? Customer::find($d['customer_id']) : null;
        if (! $lead && ! $deal && ! $customer) {
            throw new DomainException('Choose the lead, deal or customer it is about.');
        }
        $branch = $deal?->branch ?? $lead?->branch ?? ($customer?->home_branch_id ? Branch::find($customer->home_branch_id) : null);
        $branch ? $this->require($actor, 'crm.manage', $branch) : $this->requireAnywhere($actor, 'crm.manage');
        $type = isset(CrmActivity::TYPES[$d['type'] ?? '']) ? $d['type'] : 'task';
        $subject = trim((string) ($d['subject'] ?? ''));
        if ($subject === '') {
            throw new DomainException('Say what it is (e.g. “Call back about the price”).');
        }
        $due = $type === 'note' ? null : ($d['due_at'] ?? null);
        $assignee = $due ? ($d['assigned_to'] ?? $deal?->owner_id ?? $lead?->owner_id ?? $actor->id) : null;
        if ($assignee && $branch && ($u = User::find($assignee)) && ! $this->authz->allows($u, 'crm.manage', $branch)) {
            throw new DomainException("{$u->name} cannot work CRM records of this branch.");
        }

        return $this->recorder->transaction(function () use ($actor, $d, $lead, $deal, $customer, $type, $subject, $due, $assignee, $branch) {
            $a = (new CrmActivity)->forceFill([
                'tenant_id' => $actor->tenantId, 'type' => $type, 'subject' => mb_substr($subject, 0, 255), 'details' => $d['details'] ?? null,
                'lead_id' => $lead?->id ?? $deal?->lead_id, 'deal_id' => $deal?->id, 'customer_id' => $customer?->id ?? $deal?->customer_id ?? $lead?->customer_id,
                'due_at' => $due, 'done_at' => $due ? null : now(), 'outcome' => $due ? null : ($d['outcome'] ?? null),
                'assigned_to' => $assignee, 'created_by_name' => $actor->name,
            ]);
            $this->recorder->save($actor, $a, 'crm_activity.logged', branchId: $branch?->id);
            if ($lead && $lead->status === 'new' && in_array($type, ['call', 'meeting', 'message'], true) && ! $due) {
                $lead->forceFill(['status' => 'contacted']);
                $this->recorder->save($actor, $lead, 'crm_lead.updated', 'contacted', $lead->branch_id);
            }
            if ($assignee && $assignee !== $actor->id) {
                $this->tell($assignee, 'Follow-up for you: '.$a->subject, $a->about().' · due '.$a->due_at?->timezone($this->tz($actor))->format('j M H:i'), url('/admin/crm-follow-ups'));
            }

            return $a;
        });
    }

    public function completeActivity(Actor $actor, CrmActivity $a, ?string $outcome = null): CrmActivity
    {
        $this->requireAnywhere($actor, 'crm.manage');
        if ($a->done_at) {
            return $a;
        }
        $a->forceFill(['done_at' => now(), 'outcome' => $outcome ? mb_substr(trim($outcome), 0, 255) : null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $a, 'crm_activity.done', $outcome));
    }

    public function rescheduleActivity(Actor $actor, CrmActivity $a, string $dueAt): CrmActivity
    {
        $this->requireAnywhere($actor, 'crm.manage');
        if ($a->done_at) {
            throw new DomainException('This follow-up is done.');
        }
        $a->forceFill(['due_at' => $dueAt, 'reminded_at' => null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $a, 'crm_activity.rescheduled'));
    }

    /** Remind people of follow-ups now due (once each). Run every few minutes. */
    public function remindDue(string $tenantId): int
    {
        $n = 0;
        foreach (CrmActivity::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('done_at')->whereNull('reminded_at')
            ->whereNotNull('assigned_to')->where('due_at', '<=', now()->addMinutes(15))->with(['lead', 'deal', 'customer'])->limit(500)->get() as $a) {
            $this->tell($a->assigned_to, ($a->due_at->isPast() ? 'Follow-up due: ' : 'Follow-up soon: ').$a->subject, $a->about(), url('/admin/crm-follow-ups'));
            $a->forceFill(['reminded_at' => now()])->save();
            $n++;
        }

        return $n;
    }

    // --- campaigns -----------------------------------------------------------------------------------------------------

    public function saveCampaign(Actor $actor, CrmCampaign $c, array $d): CrmCampaign
    {
        $this->requireAnywhere($actor, 'crm.campaigns');
        if ($c->exists && ! in_array($c->status, ['draft', 'scheduled'], true)) {
            throw new DomainException('This campaign has gone out: copy it to send again.');
        }
        $channel = $d['channel'] ?? $c->channel;
        if (! isset(CrmCampaign::CHANNELS[$channel])) {
            throw new DomainException('Choose SMS, WhatsApp or e-mail.');
        }
        $name = trim((string) ($d['name'] ?? $c->name));
        $body = trim((string) ($d['body'] ?? $c->body));
        $subject = trim((string) ($d['subject'] ?? $c->subject)) ?: null;
        $template = trim((string) ($d['whatsapp_template'] ?? $c->whatsapp_template)) ?: null;
        if ($name === '' || $body === '') {
            throw new DomainException('Name the campaign and write its message.');
        }
        if ($channel === 'sms' && mb_strlen($body) > 480) {
            throw new DomainException('Keep an SMS under 480 characters (3 SMS).');
        }
        if ($channel === 'email' && ! $subject) {
            throw new DomainException('An e-mail needs a subject.');
        }
        if ($channel === 'whatsapp' && ! $template) {
            throw new DomainException('WhatsApp campaigns need the name of the template approved by Meta (the wording must match it).');
        }
        $sendAt = ($d['send_at'] ?? null) ?: null;

        return $this->recorder->transaction(function () use ($actor, $c, $d, $channel, $name, $body, $subject, $template, $sendAt) {
            $isNew = ! $c->exists;
            $c->forceFill(['name' => mb_substr($name, 0, 255), 'channel' => $channel, 'body' => $body, 'subject' => $channel === 'email' ? $subject : null,
                'whatsapp_template' => $channel === 'whatsapp' ? mb_substr($template, 0, 80) : null, 'audience' => self::cleanAudience((array) ($d['audience'] ?? $c->audience ?? [])),
                'send_at' => $sendAt, 'status' => 'draft']);
            if ($isNew) {
                $c->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'crm_campaign'), 'created_by_name' => $actor->name]);
            }

            return $this->recorder->save($actor, $c, $isNew ? 'crm_campaign.created' : 'crm_campaign.updated');
        });
    }

    public static function cleanAudience(array $a): array
    {
        $num = fn ($k) => isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null ? max(0, (int) $a[$k]) : null;

        return ['tags' => array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) ($a['tags'] ?? [])))),
            'branch_id' => ($a['branch_id'] ?? null) ?: null, 'bought_within_days' => $num('bought_within_days'), 'not_bought_days' => $num('not_bought_days'),
            'min_spent_minor' => $num('min_spent_minor'), 'include_leads' => (bool) ($a['include_leads'] ?? false)];
    }

    /** Customers the audience picks (before consent and addresses). */
    public function audienceCustomers(array $audience): Builder
    {
        $a = self::cleanAudience($audience);
        $q = Customer::query()->where('is_active', true);
        if ($a['tags']) {
            $q->where(function ($w) use ($a) {
                foreach ($a['tags'] as $tag) {
                    $w->orWhereJsonContains('tags', $tag);
                }
            });
        }
        if ($a['branch_id']) {
            $q->where('home_branch_id', $a['branch_id']);
        }
        $sales = fn ($days) => fn ($s) => $s->selectRaw('1')->from('sales')->whereColumn('sales.customer_id', 'customers.id')->where('sales.status', 'posted')
            ->when($days !== null, fn ($x) => $x->where('sales.occurred_at', '>=', now()->subDays($days)));
        if ($a['bought_within_days'] !== null) {
            $q->whereExists($sales($a['bought_within_days']));
        }
        if ($a['not_bought_days'] !== null) {
            $q->whereExists($sales(null))->whereNotExists($sales($a['not_bought_days']));
        }
        if ($a['min_spent_minor']) {
            $q->whereRaw('(SELECT COALESCE(SUM(total_minor), 0) FROM sales WHERE sales.customer_id = customers.id AND sales.status = ? AND sales.occurred_at >= ?) >= ?',
                ['posted', now()->subYear(), $a['min_spent_minor']]);
        }

        return $q;
    }

    /** @return array{customers: int, leads: int, reachable: int} how many the campaign would reach */
    public function preview(CrmCampaign $c): array
    {
        $customers = $this->audienceCustomers($c->audience ?? [])->get(['id', 'phone', 'email', 'consents']);
        $leads = ($c->audience['include_leads'] ?? false) ? CrmLead::whereIn('status', ['new', 'contacted', 'qualified'])->get(['id', 'phone', 'email', 'consents']) : collect();
        $reachable = $customers->concat($leads)->filter(fn ($r) => $this->addressFor($c->channel, $r) !== null && (bool) ($r->consents[$c->channel]['granted'] ?? false))->count();

        return ['customers' => $customers->count(), 'leads' => $leads->count(), 'reachable' => $reachable];
    }

    /** Send now (or at its time, by the scheduled run): every reachable person who agreed gets the message once. */
    public function send(Actor $actor, CrmCampaign $c): CrmCampaign
    {
        $this->requireAnywhere($actor, 'crm.campaigns');
        if (! in_array($c->status, ['draft', 'scheduled'], true)) {
            throw new DomainException('This campaign has already gone out.');
        }
        if ($c->send_at && $c->send_at->isFuture()) {
            $c->forceFill(['status' => 'scheduled', 'sent_by_name' => $actor->name]);

            return $this->recorder->transaction(fn () => $this->recorder->save($actor, $c, 'crm_campaign.scheduled', $c->send_at->toDateTimeString()));
        }

        return $this->deliver($actor, $c);
    }

    public function cancel(Actor $actor, CrmCampaign $c): CrmCampaign
    {
        $this->requireAnywhere($actor, 'crm.campaigns');
        if (! in_array($c->status, ['draft', 'scheduled'], true)) {
            throw new DomainException('This campaign has already gone out.');
        }
        $c->forceFill(['status' => 'cancelled']);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $c, 'crm_campaign.cancelled'));
    }

    /** Scheduled campaigns whose time has come. */
    public function sendDue(string $tenantId): int
    {
        $n = 0;
        foreach (CrmCampaign::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'scheduled')->where('send_at', '<=', now())->get() as $c) {
            $this->deliver(Actor::system($tenantId, $c->sent_by_name ?: 'Campaigns'), $c);
            $n++;
        }

        return $n;
    }

    private function deliver(Actor $actor, CrmCampaign $c): CrmCampaign
    {
        return $this->recorder->transaction(function () use ($actor, $c) {
            $c->forceFill(['status' => 'sending'])->save();
            $company = (string) Tenant::find($c->tenant_id)?->name;
            $people = $this->audienceCustomers($c->audience ?? [])->get()->map(fn ($x) => ['customer', $x]);
            if ($c->audience['include_leads'] ?? false) {
                $people = $people->concat(CrmLead::whereIn('status', ['new', 'contacted', 'qualified'])->get()->map(fn ($x) => ['lead', $x]));
            }
            $seen = [];
            $sent = $skipped = 0;
            foreach ($people as [$kind, $p]) {
                $to = $this->addressFor($c->channel, $p);
                $row = ['campaign_id' => $c->id, $kind.'_id' => $p->id, 'to' => $to];
                $skip = match (true) {
                    ! (bool) ($p->consents[$c->channel]['granted'] ?? false) => 'did not agree to '.CrmCampaign::CHANNELS[$c->channel],
                    $to === null => 'no '.($c->channel === 'email' ? 'e-mail address' : 'usable phone number'),
                    isset($seen[$to]) => 'same address as another',
                    default => null,
                };
                if ($skip) {
                    CrmCampaignRecipient::create($row + ['status' => 'skipped', 'skip_reason' => $skip]);
                    $skipped++;

                    continue;
                }
                $seen[$to] = true;
                $vars = ['customer' => $kind === 'customer' ? ($p->contact_name ?: $p->name) : $p->name, 'company' => $company];
                $body = strtr($c->body, ['{customer}' => $vars['customer'], '{company}' => $company]);
                if ($c->channel === 'email') {
                    $body .= "\n\n—\nTo stop receiving these e-mails: ".URL::signedRoute('crm.unsubscribe', ['kind' => $kind, 'id' => $p->id, 'channel' => 'email']);
                }
                preg_match_all('/\{(\w+)\}/', $c->body, $m);
                $params = array_values(array_map(fn ($k) => (string) ($vars[$k] ?? ''), array_unique($m[1])));
                $msg = app(Messenger::class)->queue($c->tenant_id, $c->channel, $to, $body, $c->subject ? strtr($c->subject, ['{customer}' => $vars['customer'], '{company}' => $company]) : null,
                    $params, $c->channel === 'whatsapp' ? $c->whatsapp_template : 'campaign', $c->number, $actor->name);
                CrmCampaignRecipient::create($row + ['status' => 'queued', 'message_id' => $msg?->id]);
                $sent++;
            }
            $c->forceFill(['status' => 'sent', 'recipients' => $sent + $skipped, 'sent' => $sent, 'skipped' => $skipped, 'sent_at' => now(), 'sent_by_name' => $c->sent_by_name ?: $actor->name]);

            return $this->recorder->save($actor, $c, 'crm_campaign.sent', "{$sent} sent, {$skipped} left out");
        });
    }

    /** Someone clicked the link in a campaign e-mail: no more campaigns on that channel. */
    public function unsubscribe(string $kind, string $id, string $channel): ?string
    {
        $p = $kind === 'lead' ? CrmLead::withoutGlobalScopes()->find($id) : Customer::withoutGlobalScopes()->find($id);
        if (! $p || ! isset(Customer::CONSENT_CHANNELS[$channel])) {
            return null;
        }
        $actor = Actor::system($p->tenant_id, 'The customer (unsubscribe link)');
        if ($p instanceof Customer) {
            app(CustomerService::class)->withdrawConsent($actor, $p, $channel, 'unsubscribe link');
        } elseif ($p->hasConsent($channel)) {
            $this->recorder->transaction(function () use ($actor, $p, $channel) {
                $p->consents = $this->consents($actor, $p->consents ?? [], [$channel => false]);
                $this->recorder->save($actor, $p, 'crm_lead.unsubscribed', $channel);
            });
        }

        return (string) Tenant::find($p->tenant_id)?->name;
    }

    // --- helpers -------------------------------------------------------------------------------------------------------

    private function addressFor(string $channel, $p): ?string
    {
        if ($channel === 'email') {
            return $p->email && filter_var($p->email, FILTER_VALIDATE_EMAIL) ? strtolower($p->email) : null;
        }

        return $p->phone && Phone::looksValid($p->phone) ? $p->phone : null;
    }

    private function consents(Actor $actor, array $current, array $wanted): array
    {
        foreach (Customer::CONSENT_CHANNELS as $channel => $label) {
            if (! array_key_exists($channel, $wanted)) {
                continue;
            }
            $granted = (bool) $wanted[$channel];
            if (($current[$channel]['granted'] ?? false) !== $granted) {
                $current[$channel] = ['granted' => $granted, 'at' => now()->toIso8601String(), 'source' => 'CRM', 'by' => $actor->name];
            }
        }

        return $current;
    }

    /** The branch a record goes to: the one chosen (if allowed), else the person's only or first branch. */
    private function branchFor(Actor $actor, ?string $branchId, string $permission): Branch
    {
        $this->assertLicensed();
        if (! $actor->user) {
            throw new AccessDenied;
        }
        $allowed = $this->authz->branchIds($actor->user, $permission);
        $branch = $branchId ? Branch::find($branchId) : Branch::where('is_active', true)->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('code')->first();
        if (! $branch || ! $this->authz->allows($actor->user, $permission, $branch)) {
            throw new AccessDenied('CRM records of this branch need the CRM rights for it.');
        }

        return $branch;
    }

    private function ownerFor(?string $userId, Branch $branch): ?string
    {
        if (! $userId) {
            return null;
        }
        $u = User::find($userId);
        if (! $u || ! $u->is_active || ! $this->authz->allows($u, 'crm.manage', $branch)) {
            throw new DomainException('Give it to someone who works CRM records of '.$branch->name.'.');
        }

        return $u->id;
    }

    private function require(Actor $actor, string $permission, ?Branch $branch): void
    {
        $this->assertLicensed();
        if (! $actor->user || ! ($branch ? $this->authz->allows($actor->user, $permission, $branch) : $this->authz->allowsAnywhere($actor->user, $permission))) {
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

    private function assertLicensed(): void
    {
        if (! $this->license->hasAddon(self::ADDON)) {
            throw new DomainException('CRM is a paid add-on: ask SyncLytics to add it to your licence.');
        }
    }

    /** A bell notification to one person, if they want CRM notices. */
    private function tell(string $userId, string $title, ?string $body, string $url): void
    {
        $u = User::withoutGlobalScopes()->find($userId);
        if (! $u || ! $u->is_active || ! NotifyPrefs::wants($u, 'crm')) {
            return;
        }
        Notification::make()->title($title)->body($body)->actions([Action::make('open')->label('Open')->url($url)->markAsRead()])->sendToDatabase($u);
    }

    private function tz(Actor $actor): string
    {
        return Tenant::find($actor->tenantId)?->timezone ?: 'UTC';
    }
}
