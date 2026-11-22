<?php

namespace App\Http\Controllers\Office;

use App\Domain\Crm\Crm;
use App\Domain\Office\CrmScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Resources\CrmCampaigns\CrmCampaignResource;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Resources\CrmLeads\CrmLeadResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\CrmActivity;
use App\Models\CrmCampaign;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM in the new back office (App\Domain\Office\CrmScreen): leads (save, make customer, lost), deals (save, quotation,
 * won, lost, reopen, move on the pipeline), follow-ups (plan or note, done, another time), campaigns (save, reach,
 * send, cancel, copy) and pipeline stages (save, archive) — each through the Crm service as the current pages.
 */
class CrmController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): CrmScreen
    {
        $this->allowed($can);

        return new CrmScreen($r->user());
    }

    private static function minor(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : FinanceUi::toMinor($v);
    }

    // -------------------------------------------------------------------------------------------- Leads

    public function leads(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CrmScreen::canView())->leads($request->query()));
    }

    public function lead(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? CrmScreen::canView() : CrmLeadResource::canCreate());

        return response()->json($screen->lead($id ? $screen->leadsQuery()->findOrFail($id) : null));
    }

    public function leadSave(Request $request, Crm $crm, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, CrmScreen::canManage());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'company_name' => ['nullable', 'string', 'max:255'], 'source' => ['required', Rule::in(array_keys(CrmLead::SOURCES))],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'string', 'max:255'], 'status' => ['required', 'in:new,contacted,qualified'],
            'interest' => ['nullable', 'string', 'max:255'], 'value' => ['nullable', 'numeric', 'min:0'], 'branch_id' => ['required', 'uuid'], 'owner_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:5000'], 'consents' => ['nullable', 'array'], 'consents.*' => ['boolean']], ['name.*' => 'Enter the person\'s name.', 'branch_id.*' => 'Choose the branch.']);
        $lead = $id ? $screen->leadsQuery()->findOrFail($id) : new CrmLead;
        $d['value_minor'] = self::minor($d['value'] ?? null);
        $d['consents'] = array_map(fn ($v) => (bool) $v, $d['consents'] ?? []);
        unset($d['value']);
        foreach (['company_name', 'phone', 'email', 'interest', 'owner_id', 'notes'] as $k) {
            $d[$k] = ($d[$k] ?? '') === '' ? null : $d[$k];
        }

        return $this->act(fn (Actor $actor) => ['id' => $crm->saveLead($actor, $lead, $d)->id, 'message' => 'Saved.']);
    }

    /** convert (Make customer, and maybe a deal) · lose (reason). */
    public function leadAction(Request $request, string $id, Crm $crm): JsonResponse
    {
        $screen = $this->screen($request, CrmScreen::canManage());
        $lead = $screen->leadsQuery()->findOrFail($id);
        $a = $request->validate(['action' => ['required', 'in:convert,lose']])['action'];
        $d = $request->validate($a === 'lose' ? ['reason' => ['required', 'string', 'max:255']] : ['deal' => ['nullable', 'string', 'max:255']], ['reason.*' => 'Say why.']);
        if ($a === 'convert') {
            $this->allowed(Erp::canAnywhere('customers.manage'));
        }

        return $this->act(function (Actor $actor) use ($a, $d, $lead, $crm) {
            if ($a === 'lose') {
                $crm->loseLead($actor, $lead, $d['reason']);

                return ['message' => 'Lead closed.'];
            }
            $r = $crm->convertLead($actor, $lead, ($d['deal'] ?? '') ?: null);

            return ['message' => 'Now a customer.', 'open' => $r['deal'] ? "/office/crm/deals/{$r['deal']->id}" : "/office/customers/{$r['customer']->id}"];
        });
    }

    // -------------------------------------------------------------------------------------------- Deals

    public function deals(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CrmScreen::canView())->deals($request->query()));
    }

    public function deal(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? CrmScreen::canView() : CrmDealResource::canCreate());

        return response()->json($screen->deal($id ? $screen->dealsQuery()->findOrFail($id) : null, $request->query()));
    }

    public function contacts(Request $request): JsonResponse
    {
        $screen = $this->screen($request, CrmScreen::canManage());

        return response()->json(['results' => $screen->contacts((string) $request->query('kind', 'customer'), mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function dealSave(Request $request, Crm $crm, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, CrmScreen::canManage());
        $d = $request->validate(['title' => ['required', 'string', 'max:255'], 'stage_id' => ['required', 'uuid'], 'customer_id' => ['nullable', 'uuid'], 'lead_id' => ['nullable', 'uuid'],
            'value' => ['nullable', 'numeric', 'min:0'], 'expected_close_on' => ['nullable', 'date_format:Y-m-d'], 'branch_id' => ['required', 'uuid'], 'owner_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:5000']], ['title.*' => 'Say what they want.', 'stage_id.*' => 'Choose the stage.', 'branch_id.*' => 'Choose the branch.']);
        $deal = $id ? $screen->dealsQuery()->findOrFail($id) : new CrmDeal;
        $d['value_minor'] = self::minor($d['value'] ?? 0) ?? 0;
        unset($d['value']);
        foreach (['customer_id', 'lead_id', 'expected_close_on', 'owner_id', 'notes'] as $k) {
            $d[$k] = ($d[$k] ?? '') === '' ? null : $d[$k];
        }

        return $this->act(fn (Actor $actor) => ['id' => $crm->saveDeal($actor, $deal, $d)->id, 'message' => 'Saved.']);
    }

    /** quote (make or open the quotation) · win · lose (reason) · reopen · move (stage, from the pipeline). */
    public function dealAction(Request $request, string $id, Crm $crm): JsonResponse
    {
        $screen = $this->screen($request, CrmScreen::canManage());
        $deal = $screen->dealsQuery()->findOrFail($id);
        $a = $request->validate(['action' => ['required', 'in:quote,win,lose,reopen,move']])['action'];
        $d = $request->validate(match ($a) {
            'lose' => ['reason' => ['required', 'string', 'max:255']],
            'move' => ['stage_id' => ['required', 'uuid']],
            default => [],
        }, ['reason.*' => 'Say why.', 'stage_id.*' => 'Choose the stage.']);
        if ($a === 'quote') {
            $this->allowed(Erp::canAnywhere('sales.orders.manage'));
        }

        return $this->act(function (Actor $actor) use ($a, $d, $deal, $crm, $screen) {
            return match ($a) {
                'quote' => (fn ($o) => ['message' => '', 'open' => CrmScreen::quoteOpen($o->id, $o->status)])($crm->quoteForDeal($actor, $deal)),
                'win' => $crm->winDeal($actor, $deal, 'marked won by hand') ? ['message' => "{$deal->number} won.", 'deal' => $screen->deal($deal->fresh())] : [],
                'lose' => $crm->loseDeal($actor, $deal, $d['reason']) ? ['message' => "{$deal->number} lost.", 'deal' => $screen->deal($deal->fresh())] : [],
                'reopen' => $crm->reopenDeal($actor, $deal) ? ['message' => 'Deal reopened.', 'deal' => $screen->deal($deal->fresh())] : [],
                default => $crm->moveDeal($actor, $deal, CrmStage::findOrFail($d['stage_id'])) ? ['message' => ''] : [],
            };
        });
    }

    public function pipeline(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CrmScreen::canPipeline())->pipeline($request->boolean('mine')));
    }

    // -------------------------------------------------------------------------------------------- Follow-ups

    public function followUps(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CrmScreen::canFollowUps())->followUps($request->query()));
    }

    /** Plan a follow-up, or note what happened, on a lead, a deal or a customer. */
    public function activity(Request $request, Crm $crm): JsonResponse
    {
        $this->screen($request, CrmScreen::canManage());
        $d = $request->validate(['type' => ['required', Rule::in(array_keys(CrmActivity::TYPES))], 'subject' => ['required', 'string', 'max:255'], 'due_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'uuid'], 'outcome' => ['nullable', 'string', 'max:255'], 'details' => ['nullable', 'string', 'max:5000'],
            'lead_id' => ['nullable', 'uuid'], 'deal_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid']],
            ['subject.*' => 'Say what it is (e.g. “Call back about the price”).']);
        $d = array_filter($d, fn ($v) => $v !== null && $v !== '');

        return $this->act(fn (Actor $actor) => ['id' => $crm->logActivity($actor, $d)->id, 'message' => ($d['due_at'] ?? null) ? 'Follow-up planned.' : 'Noted.']);
    }

    /** done (what came of it) · move (another time). */
    public function activityAction(Request $request, string $id, Crm $crm): JsonResponse
    {
        $screen = $this->screen($request, CrmScreen::canManage());
        $a = CrmActivity::whereKey($id)->where(fn ($q) => $q->whereNull('due_at')->orWhereIn('id', $screen->followUpsQuery()->select('crm_activities.id')))->firstOrFail();
        $act = $request->validate(['action' => ['required', 'in:done,move']])['action'];
        $d = $request->validate($act === 'move' ? ['due_at' => ['required', 'date']] : ['outcome' => ['nullable', 'string', 'max:255']], ['due_at.*' => 'Choose when.']);

        return $this->act(function (Actor $actor) use ($act, $d, $a, $crm) {
            $act === 'move' ? $crm->rescheduleActivity($actor, $a, $d['due_at']) : $crm->completeActivity($actor, $a, ($d['outcome'] ?? '') ?: null);

            return ['message' => $act === 'move' ? 'Moved.' : 'Done.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Campaigns

    public function campaigns(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CrmScreen::canCampaigns())->campaigns());
    }

    public function campaign(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, CrmCampaignResource::canCreate());
        $c = $id ? CrmCampaign::findOrFail($id) : null;
        $this->allowed(! $c || CrmCampaignResource::canEdit($c));

        return response()->json($screen->campaign($c));
    }

    private static function audience(array $a): array
    {
        $a['min_spent_minor'] = self::minor($a['min_spent'] ?? null);
        unset($a['min_spent']);

        return Crm::cleanAudience($a);
    }

    private const AUDIENCE = ['audience' => ['nullable', 'array'], 'audience.tags' => ['nullable', 'array'], 'audience.tags.*' => ['string', 'max:60'], 'audience.branch_id' => ['nullable', 'uuid'],
        'audience.min_spent' => ['nullable', 'numeric', 'min:0'], 'audience.bought_within_days' => ['nullable', 'integer', 'min:1'], 'audience.not_bought_days' => ['nullable', 'integer', 'min:1'],
        'audience.include_leads' => ['boolean']];

    public function reach(Request $request): JsonResponse
    {
        $screen = $this->screen($request, CrmCampaignResource::canCreate());
        $d = $request->validate(['channel' => ['required', Rule::in(array_keys(CrmCampaign::CHANNELS))]] + self::AUDIENCE);

        return response()->json(['reach' => $screen->reach($d['channel'], self::audience((array) ($d['audience'] ?? [])))]);
    }

    public function campaignSave(Request $request, Crm $crm, ?string $id = null): JsonResponse
    {
        $this->screen($request, CrmCampaignResource::canCreate());
        $c = $id ? CrmCampaign::findOrFail($id) : new CrmCampaign;
        $this->allowed(! $c->exists || CrmCampaignResource::canEdit($c));
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'industry_id' => ['nullable', 'string', 'max:40'], 'channel' => ['required', Rule::in(array_keys(CrmCampaign::CHANNELS))],
            'subject' => ['nullable', 'string', 'max:255'], 'whatsapp_template' => ['nullable', 'string', 'max:80'], 'body' => ['required', 'string', 'max:10000'],
            'send_at' => ['nullable', 'date', 'after:now']] + self::AUDIENCE, ['name.*' => 'Name the campaign.', 'body.*' => 'Write the message.', 'send_at.*' => 'Choose a time from now on.']);
        $d['audience'] = self::audience((array) ($d['audience'] ?? []));
        foreach (['subject', 'whatsapp_template', 'send_at'] as $k) {
            $d[$k] = ($d[$k] ?? '') === '' ? null : $d[$k];
        }

        return $this->act(fn (Actor $actor) => ['id' => $crm->saveCampaign($actor, $c, $d)->id, 'message' => 'Saved: send it from the list after a last look.']);
    }

    /** send (now, or schedule at its time) · cancel · copy. */
    public function campaignAction(Request $request, string $id, Crm $crm): JsonResponse
    {
        $screen = $this->screen($request, CrmCampaignResource::canCreate());
        $c = CrmCampaign::findOrFail($id);
        $a = $request->validate(['action' => ['required', 'in:send,cancel,copy']])['action'];

        return $this->act(function (Actor $actor) use ($a, $c, $crm, $screen) {
            return match ($a) {
                'send' => ['message' => $crm->send($actor, $c)->status === 'scheduled' ? 'Scheduled.' : 'Sending.', 'campaigns' => $screen->campaigns()],
                'cancel' => ['message' => $crm->cancel($actor, $c) ? 'Cancelled.' : '', 'campaigns' => $screen->campaigns()],
                default => ['message' => 'Copied.', 'open' => '/office/crm/campaigns/'.$crm->saveCampaign($actor, new CrmCampaign, ['name' => $c->name.' (copy)']
                    + $c->only(['channel', 'subject', 'body', 'whatsapp_template', 'audience']))->id],
            };
        });
    }

    // -------------------------------------------------------------------------------------------- Pipeline stages

    public function stages(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CrmScreen::canStages())->stages($request->query()));
    }

    public function stageSave(Request $request, Crm $crm, ?string $id = null): JsonResponse
    {
        $this->screen($request, CrmScreen::canStages());
        $d = $request->validate(['name' => ['required', 'string', 'max:60'], 'kind' => ['required', Rule::in(array_keys(CrmStage::KINDS))], 'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'sort' => ['nullable', 'integer']], ['name.*' => 'Name the stage.']);
        $s = $id ? CrmStage::findOrFail($id) : new CrmStage;

        return $this->act(fn (Actor $actor) => ['id' => $crm->saveStage($actor, $s, $d)->id, 'message' => 'Stage saved.']);
    }

    public function stageArchive(Request $request, string $id, ArchiveService $svc): JsonResponse
    {
        $this->screen($request, CrmScreen::canStages());
        $s = CrmStage::findOrFail($id);
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->allowed($svc->allowed($request->user(), $s));

        return $this->act(function (Actor $actor) use ($svc, $s, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $s) : $svc->archive($actor, $s, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }
}
