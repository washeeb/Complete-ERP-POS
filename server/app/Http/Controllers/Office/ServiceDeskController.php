<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ServiceDeskScreen;
use App\Domain\Service\Maintenance;
use App\Domain\Service\ServiceDesk;
use App\Domain\Support\Actor;
use App\Filament\Support\ServiceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Customer;
use App\Models\ServiceLevel;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The service desk in the new back office (App\Domain\Office\ServiceDeskScreen): open a ticket (or ask for help), and on
 * one: reply, note, pass on, work order, resolve, reopen; Deadlines — each through the ServiceDesk service as the
 * current pages.
 */
class ServiceDeskController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): ServiceDeskScreen
    {
        $this->allowed($can);

        return new ServiceDeskScreen($r->user());
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServiceDeskScreen::canView())->tickets($request->query()));
    }

    public function form(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServiceDeskScreen::canCreate())->form());
    }

    public function customers(Request $request): JsonResponse
    {
        $this->screen($request, ServiceDeskScreen::canWork());
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json(['results' => Customer::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('phone', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(30)->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'detail' => $c->phone])->all()]);
    }

    public function store(Request $request, ServiceDesk $desk): JsonResponse
    {
        $this->screen($request, ServiceDeskScreen::canCreate());
        $d = $request->validate(['kind' => ['required', Rule::in(array_keys(Ticket::KINDS))], 'priority' => ['required', Rule::in(array_keys(Ticket::PRIORITIES))],
            'source' => ['nullable', 'in:counter,phone,whatsapp,email'], 'subject' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'],
            'customer_id' => ['nullable', 'uuid'], 'contact_name' => ['nullable', 'string', 'max:120'], 'contact_phone' => ['nullable', 'string', 'max:30'],
            'contact_email' => ['nullable', 'string', 'max:255'], 'receipt_no' => ['nullable', 'string', 'max:40'], 'asset_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'],
            'assigned_to' => ['nullable', 'uuid']], ['subject.*' => 'Say in a few words what it is about.']);
        if (! ServiceDeskScreen::canWork()) { // may only ask for themselves
            $d['kind'] = 'internal';
            $d['assigned_to'] = null;
        }
        foreach (['customer_id', 'contact_name', 'contact_phone', 'contact_email', 'receipt_no', 'asset_id', 'branch_id', 'assigned_to', 'description'] as $k) {
            $d[$k] = ($d[$k] ?? '') === '' ? null : $d[$k];
        }
        // As the current form: a staff request comes "from staff", anything else by the way chosen (at the counter by default).
        $d['source'] = $d['kind'] === 'internal' ? 'staff' : ($d['source'] ?? 'counter');

        return $this->act(fn (Actor $actor) => ['id' => $desk->open($actor, $d)->id, 'message' => 'Ticket opened.']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ServiceDeskScreen::canView());

        return response()->json($screen->ticket($screen->query()->findOrFail($id)));
    }

    /** reply (channel, body, wait) · note (body) · assign (to, priority) · work_order (asset) · resolve (resolution, tell) · reopen. */
    public function action(Request $request, string $id, ServiceDesk $desk): JsonResponse
    {
        $screen = $this->screen($request, ServiceDeskScreen::canView());
        $t = $screen->query()->findOrFail($id);
        $act = (string) $request->input('action');
        switch ($act) {
            case 'reply':
                $d = $request->validate(['channel' => ['required', Rule::in(array_keys(ServiceDeskScreen::replyChannels($t)))], 'body' => ['required', 'string', 'max:5000'],
                    'wait' => ['boolean']], ['channel.*' => 'Choose how to send it.', 'body.*' => 'Write the message.']);

                return $this->act(fn (Actor $actor) => ['message' => $desk->reply($actor, $t, $d['body'], $d['channel'], (bool) ($d['wait'] ?? false)) ? 'Sent.' : '']);
            case 'note':
                $body = $request->validate(['body' => ['required', 'string', 'max:5000']], ['body.*' => 'Write the note.'])['body'];

                return $this->act(fn (Actor $actor) => ['message' => $desk->note($actor, $t, $body) ? 'Noted.' : '']);
            case 'assign':
                $this->allowed(ServiceDeskScreen::canWork());
                $d = $request->validate(['assigned_to' => ['required', 'uuid'], 'priority' => ['required', Rule::in(array_keys(Ticket::PRIORITIES))]],
                    ['assigned_to.*' => 'Choose who it goes to.']);

                return $this->act(fn (Actor $actor) => ['message' => $desk->assign($actor, $t, $d['assigned_to'], $d['priority']) ? 'Passed on.' : '']);
            case 'work_order':
                $this->allowed(ServiceUi::can('maintenance.work'));
                $asset = $request->validate(['asset_id' => ['required', 'uuid']], ['asset_id.*' => 'Choose the equipment.'])['asset_id'];

                return $this->act(fn (Actor $actor) => ['message' => 'Work order opened.',
                    'open' => '/admin/maintenance-orders/'.app(Maintenance::class)->fromTicket($actor, $t, $asset)->id]);
            case 'resolve':
                $this->allowed(ServiceDeskScreen::canWork());
                $d = $request->validate(['resolution' => ['required', 'string', 'max:5000'], 'tell' => ['nullable', Rule::in(array_keys(ServiceDeskScreen::replyChannels($t)))]],
                    ['resolution.*' => 'Say how it was resolved.']);

                return $this->act(fn (Actor $actor) => ['message' => $desk->resolve($actor, $t, $d['resolution'], ($d['tell'] ?? '') ?: null) ? 'Resolved.' : '']);
            case 'reopen':
                return $this->act(fn (Actor $actor) => ['message' => $desk->reopen($actor, $t) ? 'Reopened.' : '']);
        }
        abort(422, 'Unknown action.');
    }

    // -------------------------------------------------------------------------------------------- Deadlines

    public function levels(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ServiceDeskScreen::canLevels())->levels());
    }

    public function levelSave(Request $request, string $id, ServiceDesk $desk): JsonResponse
    {
        $this->screen($request, ServiceDeskScreen::canLevels());
        $level = ServiceLevel::findOrFail($id);
        $d = $request->validate(['respond' => ['required', 'numeric', 'min:0.25'], 'resolve' => ['required', 'numeric', 'min:0.25']],
            ['respond.*' => 'Enter the hours to answer (at least a quarter of an hour).', 'resolve.*' => 'Enter the hours to fix (at least a quarter of an hour).']);

        return $this->act(fn (Actor $actor) => ['message' => $desk->saveLevel($actor, $level, (int) round($d['respond'] * 60), (int) round($d['resolve'] * 60)) ? 'Saved.' : '']);
    }
}
