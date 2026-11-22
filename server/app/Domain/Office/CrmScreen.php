<?php

namespace App\Domain\Office;

use App\Domain\Crm\Crm;
use App\Filament\Pages\CrmFollowUps;
use App\Filament\Pages\CrmPipeline;
use App\Filament\Resources\CrmCampaigns\CrmCampaignResource;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Resources\CrmLeads\CrmLeadResource;
use App\Filament\Resources\CrmStages\CrmStageResource;
use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CrmUi;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\CrmActivity;
use App\Models\CrmCampaign;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Support\IndustryContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * CRM in the new back office (part 25 of the rewrite), as the current pages: Pipeline, Leads, Deals, Follow-ups,
 * Campaigns and Pipeline stages. Same queries (the resources' and pages', scoped to the person's branches) and rules:
 * the Crm service (CrmController).
 */
final class CrmScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function m(): array
    {
        $c = $this->user->tenant?->currency_code ?? 'GHS';

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    private function amount(?int $minor): string
    {
        $e = $this->m()['exponent'];

        return $minor === null ? '' : number_format($minor / (10 ** $e), $e, '.', '');
    }

    public static function canView(): bool
    {
        return CrmUi::canView();
    }

    public static function canManage(): bool
    {
        return CrmUi::canManage();
    }

    /** What "Follow-up" offers, on a lead, a deal or a customer. */
    public function followUpChoices(): array
    {
        return ['types' => self::choices(CrmActivity::TYPES), 'people' => self::choices(CrmUi::people()), 'me' => $this->user->id,
            'due' => now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i')];
    }

    private function activities(iterable $list): array
    {
        return collect($list)->map(fn (CrmActivity $a) => ['id' => $a->id, 'type' => CrmActivity::TYPES[$a->type] ?? $a->type, 'subject' => $a->subject, 'details' => $a->details,
            'due_at' => $a->due_at?->toIso8601String(), 'late' => ! $a->done_at && $a->due_at?->isPast(), 'who' => $a->assignee?->name, 'done' => $a->done_at?->diffForHumans(),
            'outcome' => $a->outcome, 'by' => $a->created_by_name, 'can_do' => ! $a->done_at && self::canManage()])->values()->all();
    }

    // -------------------------------------------------------------------------------------------- Leads

    public function leadsQuery(): Builder
    {
        return CrmLeadResource::getEloquentQuery();
    }

    public const LEAD_TONE = ['converted' => 'success', 'lost' => 'subtle', 'qualified' => 'informative'];

    /** @param  array<string, mixed>  $p  q, status, source, owner, open (1 open only by default, 0 closed, all), page, per */
    public function leads(array $p): array
    {
        $q = $this->leadsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('crm_leads.number', 'ilike', "%{$s}%")->orWhere('crm_leads.name', 'ilike', "%{$s}%")->orWhere('crm_leads.company_name', 'ilike', "%{$s}%")
                ->orWhere('crm_leads.phone', 'ilike', "%{$s}%")->orWhere('crm_leads.email', 'ilike', "%{$s}%"));
        }
        foreach (['status' => CrmLead::STATUSES, 'source' => CrmLead::SOURCES] as $k => $map) {
            if (array_key_exists($p[$k] ?? '', $map)) {
                $q->where("crm_leads.{$k}", $p[$k]);
            }
        }
        if (($p['owner'] ?? '') !== '') {
            $q->where('crm_leads.owner_id', $p['owner']);
        }
        $open = (string) ($p['open'] ?? '1');
        match ($open) {
            '1' => $q->whereIn('crm_leads.status', ['new', 'contacted', 'qualified']),
            '0' => $q->whereIn('crm_leads.status', ['converted', 'lost']),
            default => null,
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $manage = self::canManage();

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'open' => $open, 'statuses' => self::choices(CrmLead::STATUSES), 'sources' => self::choices(CrmLead::SOURCES),
            'people' => self::choices(CrmUi::people()), 'can_create' => CrmLeadResource::canCreate(), 'can_convert' => $manage && Erp::canAnywhere('customers.manage'),
            'rows' => $q->orderByDesc('crm_leads.created_at')->forPage($page, $per)->get()->map(fn (CrmLead $l) => ['id' => $l->id, 'number' => $l->number, 'name' => $l->name,
                'company' => $l->company_name, 'phone' => $l->phone, 'email' => $l->email, 'interest' => $l->interest, 'source' => CrmLead::SOURCES[$l->source] ?? $l->source,
                'status' => $l->status, 'status_label' => CrmLead::STATUSES[$l->status] ?? $l->status, 'tone' => self::LEAD_TONE[$l->status] ?? 'warning',
                'worth' => $l->value_minor === null ? null : (int) $l->value_minor, 'owner' => $l->owner?->name, 'open' => $l->isOpen()])->all()];
    }

    public function lead(?CrmLead $l): array
    {
        $branches = CrmUi::branches();

        return $this->m() + ['id' => $l?->id, 'number' => $l?->number, 'open' => $l ? $l->isOpen() : true, 'status_label' => $l ? CrmLead::STATUSES[$l->status] ?? $l->status : null,
            'customer' => $l?->customer_id ? ['name' => $l->customer?->name, 'open' => "/office/customers/{$l->customer_id}"] : null,
            'values' => ['name' => (string) $l?->name, 'company_name' => (string) $l?->company_name, 'source' => $l?->source ?? 'walk_in', 'phone' => (string) $l?->phone,
                'email' => (string) $l?->email, 'status' => $l?->status && in_array($l->status, ['new', 'contacted', 'qualified'], true) ? $l->status : 'new',
                'interest' => (string) $l?->interest, 'value' => $this->amount($l?->value_minor), 'branch_id' => (string) ($l?->branch_id ?? array_key_first($branches) ?? ''),
                'owner_id' => (string) ($l ? $l->owner_id : $this->user->id), 'notes' => (string) $l?->notes,
                'consents' => collect(['sms', 'whatsapp', 'email'])->mapWithKeys(fn ($c) => [$c => (bool) (($l?->consents[$c] ?? [])['granted'] ?? false)])->all()],
            'sources' => self::choices(CrmLead::SOURCES), 'statuses' => self::choices(array_slice(CrmLead::STATUSES, 0, 3, true)), 'branches' => self::choices($branches),
            'people' => self::choices(CrmUi::people()), 'can_edit' => self::canManage() && (! $l || $l->isOpen()),
            'can_convert' => $l && $l->isOpen() && self::canManage() && Erp::canAnywhere('customers.manage'),
            'activities' => $l ? $this->activities($l->activities()->with('assignee')->latest()->get()) : []] + $this->followUpChoices();
    }

    // -------------------------------------------------------------------------------------------- Deals

    public function dealsQuery(): Builder
    {
        return CrmDealResource::getEloquentQuery();
    }

    private static function dealTone(CrmDeal $d): string
    {
        return ['won' => 'success', 'lost' => 'subtle'][$d->status] ?? 'informative';
    }

    /** @param  array<string, mixed>  $p  q, status (open by default; all), stage, owner, page, per */
    public function deals(array $p): array
    {
        $q = $this->dealsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('crm_deals.number', 'ilike', "%{$s}%")->orWhere('crm_deals.title', 'ilike', "%{$s}%"));
        }
        $status = (string) ($p['status'] ?? 'open');
        if (array_key_exists($status, CrmDeal::STATUSES)) {
            $q->where('crm_deals.status', $status);
        }
        if (($p['stage'] ?? '') !== '') {
            $q->where('crm_deals.stage_id', $p['stage']);
        }
        if (($p['owner'] ?? '') !== '') {
            $q->where('crm_deals.owner_id', $p['owner']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'statuses' => self::choices(CrmDeal::STATUSES),
            'stages' => self::choices(CrmStage::orderBy('sort')->pluck('name', 'id')->all()), 'people' => self::choices(CrmUi::people()), 'can_create' => CrmDealResource::canCreate(),
            'rows' => $q->orderByDesc('crm_deals.created_at')->forPage($page, $per)->get()->map(fn (CrmDeal $d) => ['id' => $d->id, 'number' => $d->number, 'title' => $d->title,
                'contact' => $d->contactName(), 'stage' => $d->stage?->name, 'status' => $d->status, 'tone' => self::dealTone($d), 'value' => (int) $d->value_minor,
                'weighted' => $d->status === 'open' ? intdiv((int) $d->value_minor * (int) $d->stage?->probability, 100) : null,
                'closes' => $d->expected_close_on?->toDateString(), 'late' => $d->status === 'open' && $d->expected_close_on?->isPast(),
                'quote' => $d->order?->quote_number, 'owner' => $d->owner?->name, 'lost_reason' => $d->lost_reason])->all()];
    }

    public function deal(?CrmDeal $d, array $p = []): array
    {
        $branches = CrmUi::branches();
        $stages = CrmStage::where('is_active', true)->where('kind', 'open')->orderBy('sort')->pluck('name', 'id')->all();
        $manage = self::canManage();
        $customer = $d?->customer ?? (($id = $p['customer'] ?? null) ? Customer::find($id) : null);

        return $this->m() + ['id' => $d?->id, 'number' => $d?->number, 'status' => $d?->status ?? 'open', 'status_label' => CrmDeal::STATUSES[$d?->status ?? 'open'],
            'tone' => $d ? self::dealTone($d) : 'informative', 'stage' => $d?->stage?->name, 'lost_reason' => $d?->lost_reason,
            'values' => ['title' => (string) $d?->title, 'stage_id' => (string) ($d?->stage_id ?? array_key_first($stages) ?? ''), 'customer_id' => (string) $customer?->id,
                'customer_name' => $customer?->name, 'lead_id' => (string) $d?->lead_id, 'lead_name' => $d?->lead?->name, 'value' => $this->amount($d ? (int) $d->value_minor : 0),
                'expected_close_on' => (string) $d?->expected_close_on?->toDateString(), 'branch_id' => (string) ($d?->branch_id ?? array_key_first($branches) ?? ''),
                'owner_id' => (string) ($d ? $d->owner_id : $this->user->id), 'notes' => (string) $d?->notes],
            'stages' => self::choices($stages), 'branches' => self::choices($branches), 'people' => self::choices(CrmUi::people()),
            'quote' => $d?->customer_order_id ? ['number' => $d->order?->quote_number, 'open' => "/office/customer-orders/{$d->customer_order_id}"] : null,
            'actions' => ['edit' => $manage && (! $d || $d->status === 'open'), 'quote' => $d && $d->status === 'open' && $manage && Erp::canAnywhere('sales.orders.manage'),
                'win' => $d && $d->status === 'open' && $manage, 'lose' => $d && $d->status === 'open' && $manage, 'reopen' => $d && $d->status !== 'open' && $manage],
            'activities' => $d ? $this->activities($d->activities()->with('assignee')->latest()->get()) : []] + $this->followUpChoices();
    }

    /** People to choose as a deal's customer or lead, by name (or phone). */
    public function contacts(string $kind, string $q): array
    {
        return $kind === 'lead'
            ? CrmLead::whereIn('status', ['new', 'contacted', 'qualified'])->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('company_name', 'ilike', "%{$q}%"))
                ->limit(30)->get()->map(fn ($l) => ['id' => $l->id, 'label' => $l->name, 'detail' => $l->company_name])->all()
            : Customer::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('phone', 'ilike', "%{$q}%"))
                ->limit(30)->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'detail' => $c->phone])->all();
    }

    // -------------------------------------------------------------------------------------------- Pipeline

    public static function canPipeline(): bool
    {
        return CrmPipeline::canAccess();
    }

    public function pipeline(bool $mine): array
    {
        $deals = CrmUi::scope(CrmDeal::query())->where('status', 'open')->with(['customer', 'lead', 'owner', 'order'])
            ->when($mine, fn ($q) => $q->where('owner_id', $this->user->id))
            ->orderByRaw('expected_close_on IS NULL, expected_close_on')->orderByDesc('value_minor')->get()->groupBy('stage_id');
        $cols = [];
        foreach (app(Crm::class)->openStages() as $stage) {
            $list = $deals->get($stage->id, collect());
            $total = (int) $list->sum('value_minor');
            $cols[] = ['id' => $stage->id, 'name' => $stage->name, 'probability' => (int) $stage->probability, 'total' => $total, 'weighted' => intdiv($total * $stage->probability, 100),
                'deals' => $list->map(fn (CrmDeal $d) => ['id' => $d->id, 'number' => $d->number, 'title' => $d->title, 'who' => $d->contactName(), 'value' => (int) $d->value_minor,
                    'closes' => $d->expected_close_on?->format('j M'), 'late' => (bool) $d->expected_close_on?->isPast(), 'quote' => $d->order?->quote_number, 'owner' => $d->owner?->name])->values()->all()];
        }
        $q = fn () => CrmUi::scope(CrmDeal::query())->where('closed_at', '>=', now()->startOfMonth())->when($mine, fn ($q) => $q->where('owner_id', $this->user->id));

        return $this->m() + ['mine' => $mine, 'can_move' => self::canManage(), 'can_create' => CrmDealResource::canCreate(), 'columns' => $cols,
            'open' => array_sum(array_column($cols, 'total')), 'weighted' => array_sum(array_column($cols, 'weighted')),
            'won' => $q()->where('status', 'won')->count(), 'won_value' => (int) $q()->where('status', 'won')->sum('value_minor'), 'lost' => $q()->where('status', 'lost')->count()];
    }

    // -------------------------------------------------------------------------------------------- Follow-ups

    public static function canFollowUps(): bool
    {
        return CrmFollowUps::canAccess();
    }

    /** Follow-ups on records of the branches the person sees (or on customers). */
    public function followUpsQuery(): Builder
    {
        $b = Erp::branchIds('crm.view');

        return CrmActivity::query()->whereNotNull('due_at')->with(['lead', 'deal', 'customer', 'assignee'])
            ->when($b !== null, fn ($q) => $q->where(fn ($w) => $w->whereHas('deal', fn ($d) => $d->whereIn('branch_id', $b))
                ->orWhereHas('lead', fn ($l) => $l->whereIn('branch_id', $b))->orWhere(fn ($c) => $c->whereNull('deal_id')->whereNull('lead_id'))));
    }

    /** @param  array<string, mixed>  $p  mine (1 by default), open (1 by default; 0 done; all), who, type, page, per */
    public function followUps(array $p): array
    {
        $q = $this->followUpsQuery();
        $mine = (string) ($p['mine'] ?? '1') !== '0';
        if ($mine) {
            $q->where('assigned_to', $this->user->id);
        } elseif (($p['who'] ?? '') !== '') {
            $q->where('assigned_to', $p['who']);
        }
        $open = (string) ($p['open'] ?? '1');
        match ($open) {
            '1' => $q->whereNull('done_at'), '0' => $q->whereNotNull('done_at'), default => null
        };
        if (array_key_exists($p['type'] ?? '', CrmActivity::TYPES)) {
            $q->where('type', $p['type']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'mine' => $mine, 'open' => $open, 'types' => self::choices(CrmActivity::TYPES), 'people' => self::choices(CrmUi::people()),
            'rows' => $q->orderBy('due_at')->forPage($page, $per)->get()->map(fn (CrmActivity $a) => ['id' => $a->id, 'due_at' => $a->due_at?->toIso8601String(),
                'late' => ! $a->done_at && $a->due_at?->isPast(), 'type' => CrmActivity::TYPES[$a->type] ?? $a->type, 'subject' => $a->subject, 'details' => $a->details,
                'with' => $a->about(), 'open' => match (true) {
                    $a->deal_id !== null && $a->deal?->status === 'open' => "/office/crm/deals/{$a->deal_id}",
                    $a->lead_id !== null && (bool) $a->lead?->isOpen() => "/office/crm/leads/{$a->lead_id}",
                    $a->customer_id !== null && CustomerResource::canViewAny() => "/office/customers/{$a->customer_id}",
                    default => null,
                }, 'who' => $a->assignee?->name, 'done' => $a->done_at?->diffForHumans(), 'outcome' => $a->outcome, 'can_do' => ! $a->done_at && self::canManage()])->all()];
    }

    // -------------------------------------------------------------------------------------------- Campaigns

    public static function canCampaigns(): bool
    {
        return CrmCampaignResource::canViewAny();
    }

    public const CAMPAIGN_TONE = ['sent' => 'success', 'scheduled' => 'informative', 'cancelled' => 'subtle'];

    public function campaigns(): array
    {
        $create = CrmCampaignResource::canCreate();
        $crm = app(Crm::class);

        return ['can_create' => $create, 'rows' => CrmCampaign::orderByDesc('created_at')->limit(200)->get()->map(fn (CrmCampaign $c) => ['id' => $c->id, 'number' => $c->number,
            'name' => $c->name, 'body' => mb_strimwidth($c->body, 0, 80, '…'), 'channel' => CrmCampaign::CHANNELS[$c->channel] ?? $c->channel, 'status' => $c->status,
            'status_label' => CrmCampaign::STATUSES[$c->status] ?? $c->status, 'tone' => self::CAMPAIGN_TONE[$c->status] ?? 'warning', 'send_at' => $c->send_at?->format('j M H:i'),
            'sent' => (int) $c->sent, 'skipped' => (int) $c->skipped,
            'delivered' => $c->status === 'sent' ? Message::where('reference', $c->number)->where('status', 'sent')->count() : null,
            'failed' => $c->status === 'sent' ? Message::where('reference', $c->number)->whereIn('status', ['failed', 'not_configured'])->count() : null,
            'sent_at' => $c->sent_at?->format('j M Y H:i'), 'can_edit' => CrmCampaignResource::canEdit($c), 'can_send' => $c->status === 'draft' && $create,
            'later' => (bool) $c->send_at?->isFuture(), 'reach' => $c->status === 'draft' && $create ? $crm->preview($c)['reachable'] : null,
            'can_cancel' => in_array($c->status, ['draft', 'scheduled'], true) && $create])->all()];
    }

    public function campaign(?CrmCampaign $c): array
    {
        $a = $c?->audience ?? [];
        $industries = IndustryContext::choices($this->user);
        if ($c?->exists && $c->industry_id === null) {
            $industries[IndustryContext::SHARED] = 'Shared by all industries';
        }

        return $this->m() + ['id' => $c?->id, 'number' => $c?->number, 'channels' => self::choices(CrmCampaign::CHANNELS),
            'branches' => self::choices(Branch::where('is_active', true)->orderBy('code')->pluck('name', 'id')->all()),
            'tags' => Customer::whereNotNull('tags')->pluck('tags')->flatten()->filter()->unique()->values()->all(),
            'industries' => count(array_diff_key($industries, [IndustryContext::SHARED => true])) > 1 || isset($industries[IndustryContext::SHARED]) ? self::choices($industries) : null,
            'values' => ['name' => (string) $c?->name, 'industry_id' => $c ? (string) ($c->industry_id ?? IndustryContext::SHARED) : (string) IndustryContext::defaultId(),
                'channel' => $c?->channel ?? 'sms', 'subject' => (string) $c?->subject, 'whatsapp_template' => (string) $c?->whatsapp_template, 'body' => (string) $c?->body,
                'send_at' => (string) $c?->send_at?->format('Y-m-d\TH:i'),
                'audience' => ['tags' => array_values($a['tags'] ?? []), 'branch_id' => (string) ($a['branch_id'] ?? ''), 'min_spent' => $this->amount($a['min_spent_minor'] ?? null),
                    'bought_within_days' => (string) ($a['bought_within_days'] ?? ''), 'not_bought_days' => (string) ($a['not_bought_days'] ?? ''),
                    'include_leads' => (bool) ($a['include_leads'] ?? false)]]];
    }

    /** "Reaches 120 of 340 picked." for the audience being chosen. */
    public function reach(string $channel, array $audience): string
    {
        $c = new CrmCampaign(['channel' => isset(CrmCampaign::CHANNELS[$channel]) ? $channel : 'sms', 'audience' => $audience]);
        $r = app(Crm::class)->preview($c);
        $picked = $r['customers'] + $r['leads'];

        return "Reaches {$r['reachable']} of the {$picked} picked by these choices.".($picked > $r['reachable'] ? ' The others did not agree to '.CrmCampaign::CHANNELS[$c->channel]
            .' or have no '.($c->channel === 'email' ? 'e-mail address.' : 'usable number.') : '');
    }

    // -------------------------------------------------------------------------------------------- Pipeline stages

    public static function canStages(): bool
    {
        return CrmStageResource::canViewAny();
    }

    public function stages(array $p): array
    {
        $q = CrmStage::query();
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('archived_at'), 'both' => null, default => $q->whereNull('archived_at')
        };
        $archive = new CompanyScreen($this->user);

        return ['kinds' => self::choices(CrmStage::KINDS), 'rows' => $q->orderBy('sort')->get()->map(fn (CrmStage $s) => ['id' => $s->id, 'sort' => (int) $s->sort, 'name' => $s->name,
            'kind' => $s->kind, 'kind_label' => CrmStage::KINDS[$s->kind] ?? $s->kind, 'probability' => (int) $s->probability,
            'deals' => CrmDeal::where('stage_id', $s->id)->where('status', 'open')->count()] + $archive->archiveOf($s))->all()];
    }

    public static function quoteOpen(string $orderId, string $status): string
    {
        return $status === 'quotation' ? "/office/customer-orders/{$orderId}/edit" : "/office/customer-orders/{$orderId}";
    }

    public static function canOrders(): bool
    {
        return CustomerOrderResource::canViewAny();
    }
}
