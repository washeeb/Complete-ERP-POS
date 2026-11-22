<?php

namespace App\Domain\Office;

use App\Filament\Pages\ServiceLevels;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\ServiceUi;
use App\Models\Branch;
use App\Models\MaintenanceAsset;
use App\Models\Message;
use App\Models\ServiceLevel;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * The service desk in the new back office (part 28 of the rewrite), as the current pages: Tickets (list, new, one ticket
 * with its conversation) and Deadlines. Same queries (the resource's, scoped to the person's branches — or only their own
 * requests) and rules: the ServiceDesk service (ServiceDeskController).
 */
final class ServiceDeskScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const STATUS_TONE = ['new' => 'warning', 'open' => 'informative', 'resolved' => 'success', 'closed' => 'success', 'waiting' => 'subtle'];

    public const PRIORITY_TONE = ['urgent' => 'danger', 'high' => 'warning', 'low' => 'subtle', 'normal' => 'informative'];

    public const ORDER = "CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END";

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    public static function canView(): bool
    {
        return TicketResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return TicketResource::canCreate();
    }

    public static function canWork(): bool
    {
        return ServiceUi::can('helpdesk.work');
    }

    public static function canLevels(): bool
    {
        return ServiceLevels::canAccess();
    }

    public function query(): Builder
    {
        return TicketResource::getEloquentQuery();
    }

    private function from(Ticket $t): string
    {
        return trim(($t->customer?->name ?? $t->contact_name ?? '').' · '.(Ticket::SOURCES[$t->source] ?? $t->source), ' ·');
    }

    /** @param  array<string, mixed>  $p  q, open (1 by default, 0 closed, all), mine, late, kind, priority, page, per */
    public function tickets(array $p): array
    {
        $q = $this->query();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('tickets.number', 'ilike', "%{$s}%")->orWhere('tickets.subject', 'ilike', "%{$s}%")->orWhere('tickets.contact_name', 'ilike', "%{$s}%")
                ->orWhere('tickets.contact_phone', 'ilike', "%{$s}%")->orWhere('tickets.contact_email', 'ilike', "%{$s}%"));
        }
        $open = (string) ($p['open'] ?? '1');
        match ($open) {
            '1' => $q->whereIn('tickets.status', ['new', 'open', 'waiting']),
            '0' => $q->whereIn('tickets.status', ['resolved', 'closed']),
            default => $open = 'all',
        };
        if (($p['mine'] ?? '') === '1') {
            $q->where('tickets.assigned_to', $this->user->id);
        }
        if (($p['late'] ?? '') === '1') {
            $q->whereIn('tickets.status', ['new', 'open'])->where(fn ($w) => $w->where(fn ($a) => $a->whereNull('first_response_at')->where('respond_by', '<', now()))
                ->orWhere('resolve_by', '<', now()));
        }
        foreach (['kind' => Ticket::KINDS, 'priority' => Ticket::PRIORITIES] as $k => $map) {
            if (isset($map[$p[$k] ?? ''])) {
                $q->where("tickets.{$k}", $p[$k]);
            }
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $view = ServiceUi::can('helpdesk.view');

        return ['total' => $total, 'page' => $page, 'per' => $per, 'open' => $open, 'kinds' => self::choices(Ticket::KINDS), 'priorities' => self::choices(Ticket::PRIORITIES),
            'title' => $view ? 'Tickets' : 'My requests', 'all' => $view, 'work' => self::canWork(), 'can_create' => self::canCreate(),
            'create_label' => self::canWork() ? 'New ticket' : 'Ask for help', 'channels' => ServiceUi::can('helpdesk.manage') ? $this->channels() : null,
            'rows' => $q->orderByDesc('tickets.created_at')->forPage($page, $per)->get()->map(fn (Ticket $t) => ['id' => $t->id, 'number' => $t->number, 'subject' => $t->subject,
                'from' => $this->from($t), 'kind' => Ticket::KINDS[$t->kind] ?? $t->kind, 'priority' => Ticket::PRIORITIES[$t->priority] ?? $t->priority,
                'priority_tone' => self::PRIORITY_TONE[$t->priority] ?? 'informative', 'status_label' => Ticket::STATUSES[$t->status] ?? $t->status,
                'tone' => self::STATUS_TONE[$t->status] ?? 'subtle', 'due' => $t->deadline()?->diffForHumans(), 'due_what' => $t->deadline() ? ($t->first_response_at ? 'to fix' : 'to answer') : null,
                'late' => $t->late(), 'with' => $t->assignee?->name, 'opened' => $t->created_at?->diffForHumans()])->all()];
    }

    /** Where tickets come from: the web form's link and the e-mail forwarding address, as they are for this company. */
    public function channels(): array
    {
        $tenant = $this->user->tenant;
        $s = app(Settings::class);
        $key = (string) $s->get('service.email_key', $tenant->id);

        return ['web_form' => $s->get('service.web_form', $tenant->id) ? route('service.help', ['company' => $tenant->slug]) : null,
            'email' => strlen($key) >= 24 ? route('service.email', ['key' => $key]) : null];
    }

    private function assets(): array
    {
        return MaintenanceAsset::whereNull('archived_at')->where('status', '<>', 'retired')->orderBy('number')->get()
            ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->number} {$a->name}"])->all();
    }

    /** What "New ticket" (or "Ask for help", for someone who may only ask for themselves) offers. */
    public function form(): array
    {
        $staffOnly = ! self::canWork();

        return ['staff_only' => $staffOnly, 'kinds' => self::choices($staffOnly ? ['internal' => Ticket::KINDS['internal']] : Ticket::KINDS),
            'priorities' => self::choices(Ticket::PRIORITIES), 'sources' => self::choices(['counter' => 'At the counter', 'phone' => 'Phone call', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail']),
            'branches' => self::choices(Branch::where('is_active', true)->orderBy('code')->pluck('name', 'id')->all()), 'assets' => $this->assets(),
            'people' => $staffOnly ? [] : self::choices(ServiceUi::people('helpdesk.work')),
            'values' => ['kind' => $staffOnly ? 'internal' : 'support', 'priority' => 'normal', 'source' => 'counter', 'subject' => '', 'description' => '', 'customer_id' => '',
                'customer_name' => null, 'contact_name' => '', 'contact_phone' => '', 'contact_email' => '', 'receipt_no' => '', 'asset_id' => '', 'branch_id' => '', 'assigned_to' => '']];
    }

    /** @return array<string, string> how a reply can reach them */
    public static function replyChannels(Ticket $t): array
    {
        return array_filter(['whatsapp' => $t->contact_phone ? 'WhatsApp' : null, 'sms' => $t->contact_phone ? 'SMS' : null, 'email' => $t->contact_email ? 'E-mail' : null]);
    }

    public function ticket(Ticket $t): array
    {
        $work = self::canWork();
        $open = $t->isOpen();
        $channels = self::replyChannels($t);
        $mine = $t->raised_by === $this->user->id;
        $iso = fn ($d) => $d?->toIso8601String();

        return ['id' => $t->id, 'number' => $t->number, 'subject' => $t->subject, 'status' => $t->status, 'status_label' => Ticket::STATUSES[$t->status] ?? $t->status,
            'tone' => self::STATUS_TONE[$t->status] ?? 'subtle', 'priority' => $t->priority, 'priority_label' => Ticket::PRIORITIES[$t->priority] ?? $t->priority,
            'kind' => Ticket::KINDS[$t->kind] ?? $t->kind, 'source' => Ticket::SOURCES[$t->source] ?? $t->source, 'description' => $t->description,
            'who' => $t->customer?->name ?? $t->contact_name, 'contact' => implode(' · ', array_filter([$t->contact_phone, $t->contact_email])),
            'with' => $t->assignee?->name, 'assigned_to' => $t->assigned_to,
            'answer' => $t->first_response_at ? ['done' => true, 'at' => $iso($t->first_response_at)] : ['done' => false, 'at' => $iso($t->respond_by),
                'late' => $open && (bool) $t->respond_by?->isPast() && $t->status !== 'waiting'],
            'fix' => $t->resolved_at ? ['done' => true, 'at' => $iso($t->resolved_at)] : ($t->status === 'waiting' ? ['done' => false, 'at' => null, 'waiting' => true]
                : ['done' => false, 'at' => $iso($t->resolve_by), 'late' => $t->late()]),
            'receipt' => $t->receipt_no, 'warranty' => match ($t->under_warranty) {
                true => 'Under warranty', false => 'Out of warranty', default => null
            }, 'asset' => $t->asset ? "{$t->asset->number} {$t->asset->name}" : null, 'asset_id' => $t->asset_id,
            'resolution' => $t->resolution, 'rating' => $t->rating,
            'messages' => $t->messages()->with('message')->orderBy('created_at')->get()->map(fn ($m) => ['id' => $m->id, 'direction' => $m->direction,
                'who' => $m->author_name, 'channel' => $m->channel ? (Ticket::SOURCES[$m->channel] ?? ucfirst($m->channel)) : null, 'at' => $iso($m->created_at),
                'delivery' => $m->message ? (Message::STATUSES[$m->message->status] ?? $m->message->status) : null, 'body' => $m->body])->all(),
            'channels' => self::choices($channels), 'priorities' => self::choices(Ticket::PRIORITIES), 'people' => $work ? self::choices(ServiceUi::people('helpdesk.work')) : [],
            'assets' => ServiceUi::can('maintenance.work') ? $this->assets() : [], 'me' => $this->user->id,
            'actions' => ['reply' => $open && $t->kind !== 'internal' && $work && $channels !== [], 'note' => $work || ($t->kind === 'internal' && $mine),
                'assign' => $open && $work, 'work_order' => $open && ServiceUi::can('maintenance.work'), 'resolve' => $open && $work, 'reopen' => ! $open && ($work || $mine),
                'tell' => $t->kind !== 'internal' && $channels !== []]];
    }

    // -------------------------------------------------------------------------------------------- Deadlines

    public function levels(): array
    {
        return ['rows' => ServiceLevel::query()->orderByRaw(self::ORDER)->get()->map(fn (ServiceLevel $l) => ['id' => $l->id,
            'priority' => Ticket::PRIORITIES[$l->priority] ?? $l->priority, 'respond' => ServiceLevel::span((int) $l->respond_minutes),
            'resolve' => ServiceLevel::span((int) $l->resolve_minutes),
            'values' => ['respond' => rtrim(rtrim(number_format($l->respond_minutes / 60, 2, '.', ''), '0'), '.'),
                'resolve' => rtrim(rtrim(number_format($l->resolve_minutes / 60, 2, '.', ''), '0'), '.')]])->all()];
    }
}
