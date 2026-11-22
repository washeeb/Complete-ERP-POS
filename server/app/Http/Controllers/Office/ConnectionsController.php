<?php

namespace App\Http\Controllers\Office;

use App\Domain\Api\ApiClients;
use App\Domain\Office\ConnectionsScreen;
use App\Domain\Payments\MomoCollections;
use App\Domain\Pos\TillFileImport;
use App\Domain\Remote\RemoteAccess;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Domain\Sync\NodeSetup;
use App\Domain\Sync\SyncEngine;
use App\Domain\Sync\SyncFile;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\ApiClient;
use App\Models\MomoGateway;
use App\Models\SupportAccessGrant;
use App\Models\SyncPeer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * System in the new back office, second half (App\Domain\Office\ConnectionsScreen): API clients (new, new key, webhook,
 * revoke), Mobile money (the list and the payment provider), Support access (give, withdraw) and Sync Center (sync now,
 * review incoming, what syncs, export a sync file, disable / enable, full resync, add a branch server, import a sync or
 * till file) — each through the same service as the current pages. A new key or secret is answered once, never kept.
 */
class ConnectionsController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): ConnectionsScreen
    {
        $this->allowed($can);

        return new ConnectionsScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- API clients

    public function apiClients(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ConnectionsScreen::canApi())->apiClients());
    }

    public function apiCreate(Request $request, ApiClients $svc): JsonResponse
    {
        $screen = $this->screen($request, ConnectionsScreen::canApi());
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => [Rule::in(array_keys(ApiClients::SCOPES))],
            'user_id' => ['required', 'uuid'], 'branch_id' => ['nullable', 'uuid']], ['name.*' => 'Name the system.', 'scopes.*' => 'Choose at least one scope.', 'user_id.*' => 'Choose who it acts as.']);

        return $this->act(function (Actor $actor) use ($svc, $d, $screen) {
            [$client, $key] = $svc->create($actor, $d + ['branch_id' => null]);

            return ['message' => 'API client created.', 'secret' => ['title' => "Key for {$client->name}", 'value' => $key], 'clients' => $screen->apiClients()];
        });
    }

    /** key (a new key, for rotation) · webhook (address, events; its signing secret) · revoke (reason). */
    public function apiAction(Request $request, string $id, ApiClients $svc): JsonResponse
    {
        $screen = $this->screen($request, ConnectionsScreen::canApi());
        $c = ApiClient::findOrFail($id);
        $a = $request->validate(['action' => ['required', 'in:key,webhook,revoke']])['action'];
        $d = $request->validate(match ($a) {
            'webhook' => ['url' => ['required', 'url', 'max:500'], 'events' => ['required', 'array', 'min:1'], 'events.*' => [Rule::in(array_keys(ApiClients::EVENTS))]],
            'revoke' => ['reason' => ['required', 'string', 'max:255']],
            default => [],
        }, ['url.*' => 'Enter the address (https://…).', 'events.*' => 'Choose at least one event.', 'reason.*' => 'Say why.']);

        return $this->act(function (Actor $actor) use ($a, $d, $c, $svc, $screen) {
            $out = match ($a) {
                'key' => ['message' => 'New key made.', 'secret' => ['title' => "Key for {$c->name}", 'value' => $svc->issueKey($actor, $c)]],
                'webhook' => ['message' => 'Webhook added.', 'secret' => ['title' => "Signing secret for {$d['url']}", 'value' => $svc->addWebhook($actor, $actor->tenantId, $c, $d['url'], $d['events'])[1]]],
                default => ['message' => $svc->revoke($actor, $c, $d['reason']) ? 'Revoked: its keys stopped working.' : ''],
            };

            return $out + ['clients' => $screen->apiClients()];
        });
    }

    // -------------------------------------------------------------------------------------------- Mobile money

    public function momo(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ConnectionsScreen::canMomo())->momo($request->query()));
    }

    /** The payment provider: an empty secret keeps the saved one. */
    public function momoGateway(Request $request, MomoCollections $svc): JsonResponse
    {
        $this->screen($request, ConnectionsScreen::canMomo() && Erp::can('payments.momo.manage'));
        $d = $request->validate(['provider' => ['required', Rule::in(array_keys(MomoGateway::PROVIDERS))], 'credentials' => ['nullable', 'array'], 'credentials.*' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean']], ['provider.*' => 'Choose the provider.']);

        return $this->act(function (Actor $actor) use ($svc, $d) {
            $svc->saveGateway($actor, ['provider' => $d['provider'], 'credentials' => array_intersect_key($d['credentials'] ?? [], MomoGateway::FIELDS[$d['provider']] ?? []),
                'is_active' => (bool) ($d['is_active'] ?? false)]);

            return ['message' => 'Payment provider saved.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Support access

    public function support(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ConnectionsScreen::canSupport())->supportAccess());
    }

    public function supportGrant(Request $request, RemoteAccess $svc): JsonResponse
    {
        $screen = $this->screen($request, ConnectionsScreen::canSupport() && Erp::can('support.access.grant'));
        $d = $request->validate(['level' => ['required', Rule::in(array_keys(SupportAccessGrant::LEVELS))], 'branch_id' => ['nullable', 'uuid'],
            'until' => ['required', 'in:1,2,4,8,24,report'], 'support_report_id' => ['required_if:until,report', 'nullable', 'uuid'], 'reason' => ['required', 'string', 'max:255']],
            ['level.*' => 'Choose what support may do.', 'until.*' => 'Choose for how long.', 'support_report_id.*' => 'Choose the problem report.', 'reason.*' => 'Say what support is helping with.']);

        return $this->act(function (Actor $actor) use ($svc, $d, $screen) {
            $svc->grant($actor, ['level' => $d['level'], 'branch_id' => ($d['branch_id'] ?? '') ?: null, 'reason' => $d['reason'],
                'hours' => $d['until'] === 'report' ? null : (int) $d['until'], 'support_report_id' => $d['until'] === 'report' ? $d['support_report_id'] : null]);

            return ['message' => 'Support access given.', 'support' => $screen->supportAccess()];
        });
    }

    public function supportRevoke(Request $request, string $id, RemoteAccess $svc): JsonResponse
    {
        $screen = $this->screen($request, ConnectionsScreen::canSupport() && Erp::can('support.access.grant'));
        $g = SupportAccessGrant::findOrFail($id);

        return $this->act(function (Actor $actor) use ($svc, $g, $screen) {
            $svc->revoke($actor, $g);

            return ['message' => 'Support access withdrawn.', 'support' => $screen->supportAccess()];
        });
    }

    // -------------------------------------------------------------------------------------------- Sync Center

    public function sync(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ConnectionsScreen::canSync())->sync());
    }

    /** sync · decide (group, accept) · modes (group => mode) · toggle · resync, as the current page's buttons. */
    public function syncAction(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ConnectionsScreen::canSync());
        $p = SyncPeer::findOrFail($id);
        $a = $request->validate(['action' => ['required', 'in:sync,decide,modes,toggle,resync']])['action'];
        if ($a === 'sync') {
            abort_unless($p->base_url && $p->is_active, 422, 'This server cannot be reached from here.');
            @set_time_limit(120);
            $s = app(SyncEngine::class)->run($p);

            return response()->json(['good' => ! $s['error'], 'message' => $s['error'] ? "Sync failed: {$s['error']}"
                : "Sent {$s['pushed']}, received {$s['pulled']}. Applied {$s['applied']}, waiting for review {$s['staged']}, failed {$s['failed']}.", 'sync' => $screen->sync()]);
        }
        if ($a === 'decide') {
            $d = $request->validate(['group' => ['required', 'string', 'max:60'], 'accept' => ['required', 'boolean']], ['group.*' => 'Choose the group.']);
            $ids = $p->inbox()->whereIn('status', ['staged', 'skipped'])->where('sync_group', $d['group'])->pluck('id')->all();
            $n = app(SyncEngine::class)->decide($p, $ids, (bool) $d['accept'], $request->user()->id);

            return response()->json(['good' => true, 'message' => ($d['accept'] ? 'Applied ' : 'Rejected ').$n.' change(s).', 'sync' => $screen->sync()]);
        }
        if ($a === 'resync') {
            abort_unless((bool) $p->base_url, 422);
            app(SyncEngine::class)->resetForFullResync($p);

            return response()->json(['good' => true, 'message' => 'Full resync will run at the next sync.', 'sync' => $screen->sync()]);
        }
        $modes = $a === 'modes' ? $request->validate(['groups' => ['required', 'array'], 'groups.*' => [Rule::in(array_keys(SyncPeer::MODES))]])['groups'] : [];

        return $this->act(function (Actor $actor) use ($a, $p, $modes, $screen) {
            if ($a === 'modes') {
                $p->groups = array_merge($p->groups ?? [], array_intersect_key($modes, array_flip($p->groupsWeReceive())));
                app(Recorder::class)->save($actor, $p, 'sync.modes_changed');

                return ['good' => true, 'message' => 'Saved.', 'sync' => $screen->sync()];
            }
            $p->is_active = ! $p->is_active;
            app(Recorder::class)->save($actor, $p, $p->is_active ? 'sync.peer_enabled' : 'sync.peer_disabled');

            return ['good' => true, 'message' => $p->is_active ? 'Enabled.' : 'Disabled.', 'sync' => $screen->sync()];
        });
    }

    public function syncExport(Request $request, string $id, SyncFile $file): StreamedResponse
    {
        $this->screen($request, ConnectionsScreen::canSync());
        $p = SyncPeer::findOrFail($id);

        return response()->streamDownload(fn () => print ($file->export($p)), 'sync-'.config('erp.node.id').'-to-'.$p->node_id.'-'.now()->format('Ymd-His').'.erpsync');
    }

    /** Register a branch server: its join command and secret are answered once. */
    public function addBranch(Request $request, NodeSetup $setup): JsonResponse
    {
        $screen = $this->screen($request, ConnectionsScreen::canSync() && config('erp.node.role') !== 'branch');
        $d = $request->validate(['node_id' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:80']],
            ['node_id.*' => 'Enter the branch server id (ERP_NODE_ID on that server).', 'name.*' => 'Name the branch server.']);

        return $this->act(function (Actor $actor) use ($setup, $d, $screen) {
            $r = $setup->addBranchNode($actor, $d['node_id'], $d['name']);

            return ['message' => 'Branch server registered.', 'secret' => ['title' => 'On the branch server run this, and paste the secret when asked (shown once)',
                'value' => 'php artisan erp:node:join '.url('/')." {$r['hq_node_id']} {$r['tenant_id']}", 'second' => $r['secret']], 'sync' => $screen->sync()];
        });
    }

    /** sync (an .erpsync file from another server) · till (a till's unsent sales, exported on the till). */
    public function import(Request $request): JsonResponse
    {
        $screen = $this->screen($request, ConnectionsScreen::canSync());
        $d = $request->validate(['kind' => ['required', 'in:sync,till'], 'file' => ['required', 'file', 'max:512000']], ['file.*' => 'Choose the file.']);
        $content = (string) file_get_contents($d['file']->getRealPath());
        try {
            if ($d['kind'] === 'sync') {
                $r = app(SyncFile::class)->import($content);

                return response()->json(['good' => true, 'message' => "Imported {$r['received']} change(s) from {$r['peer']->name}. Applied {$r['applied']}, waiting for review {$r['staged']}, failed {$r['failed']}.",
                    'sync' => $screen->sync()]);
            }
            $r = app(TillFileImport::class)->import(Actor::user($request->user(), 'web'), $content);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
        $by = collect($r['results'])->countBy('status');

        return response()->json(['good' => true, 'message' => "{$r['device']->name}: ".($by['accepted'] ?? 0).' applied, '.($by['duplicate'] ?? 0).' already here, '.($by['rejected'] ?? 0)
            .' refused. Refused items are in the Review queue.', 'sync' => $screen->sync()]);
    }
}
