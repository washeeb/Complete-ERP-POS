<?php

namespace App\Http\Controllers\Office;

use App\Domain\Access\AccessRequests;
use App\Domain\Access\AccessService;
use App\Domain\Office\RolesScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Resources\Roles\RoleResource;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\AccessRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Roles and access requests in the new back office (App\Domain\Office\RolesScreen): the roles, one role, Add and Change
 * (name, level, description, permissions; technical only when added), Archive and Restore; and Approve, Turn down and
 * Withdraw on access requests, through AccessService, AccessRequests and ArchiveService, as the current screens.
 */
class RolesController extends Controller
{
    use ActsOnAccess;

    public function index(Request $request): JsonResponse
    {
        $this->allowed(RolesScreen::canView());

        return response()->json((new RolesScreen($request->user()))->list($request->query()));
    }

    public function form(Request $request): JsonResponse
    {
        $this->allowed(RolesScreen::canView());

        return response()->json((new RolesScreen($request->user()))->form());
    }

    public function show(Request $request, string $role): JsonResponse
    {
        $this->allowed(RolesScreen::canView());
        $screen = new RolesScreen($request->user());

        return response()->json(['role' => $screen->show($screen->find($role) ?? abort(404))]);
    }

    public function store(Request $request, AccessService $access): JsonResponse
    {
        $this->allowed(RolesScreen::canView());
        abort_unless(RolesScreen::canManage(), 403, 'You cannot add roles.');
        $d = $this->validated($request, true);
        $attributes = ['name' => $d['name'], 'description' => $d['description'] ?? null, 'security_level' => (int) $d['security_level'], 'is_technical' => (bool) ($d['is_technical'] ?? false),
            'key' => Str::slug($d['name'], '_').'_'.Str::lower(Str::random(4))]; // never collides with seeded keys

        return $this->act(function (Actor $actor) use ($access, $attributes, $d) {
            $role = $access->saveRole($actor, new Role, $attributes, $d['permissions'] ?? []);

            return ['role' => ['id' => $role->id, 'open' => "/office/roles/{$role->id}"], 'message' => "{$role->name} added."];
        });
    }

    public function update(Request $request, string $role, AccessService $access): JsonResponse
    {
        $this->allowed(RolesScreen::canView());
        $r = (new RolesScreen($request->user()))->find($role) ?? abort(404);
        abort_unless(RoleResource::canEdit($r), 403, 'You cannot change this role.');
        $d = $this->validated($request, false);
        $attributes = ['name' => $d['name'], 'description' => $d['description'] ?? null, 'security_level' => (int) $d['security_level']]; // technical is fixed once added

        return $this->act(fn (Actor $actor) => ['message' => $access->saveRole($actor, $r, $attributes, $d['permissions'] ?? [], $d['reason'] ?? null)->name.' saved.']);
    }

    public function archive(Request $request, string $role, ArchiveService $archive): JsonResponse
    {
        $this->allowed(RolesScreen::canView());
        $r = (new RolesScreen($request->user()))->find($role) ?? abort(404);
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);

        return $this->act(function (Actor $actor) use ($archive, $r, $d) {
            $archive->archive($actor, $r, $d['reason']);

            return ['message' => "{$r->name} archived."];
        });
    }

    public function restore(Request $request, string $role, ArchiveService $archive): JsonResponse
    {
        $this->allowed(RolesScreen::canView());
        $r = (new RolesScreen($request->user()))->find($role) ?? abort(404);

        return $this->act(function (Actor $actor) use ($archive, $r) {
            $archive->restore($actor, $r);

            return ['message' => "{$r->name} restored."];
        });
    }

    public function requests(Request $request): JsonResponse
    {
        $this->allowed(RolesScreen::canRequests());

        return response()->json((new RolesScreen($request->user()))->requests($request->query()));
    }

    /** Approve (done now, in the approver's name), Turn down (with a reason the asker is told), or Withdraw (the asker). */
    public function decide(Request $request, string $accessRequest, string $step, AccessRequests $requests): JsonResponse
    {
        $this->allowed(RolesScreen::canRequests());
        $r = AccessRequest::find($accessRequest) ?? abort(404);
        $d = $request->validate(['note' => [$step === 'reject' ? 'required' : 'nullable', 'string', 'max:250']], ['note.required' => 'Say why (the person who asked is told).']);

        return $this->act(function (Actor $actor) use ($requests, $r, $step, $d) {
            if ($step === 'approve') {
                $done = $requests->approve($actor, $r, $d['note'] ?? null);

                return $done->status === 'failed' ? ['failed' => true, 'message' => 'Approved, but it could not be done: '.$done->decision_note] : ['message' => 'Approved and done.'];
            }
            $step === 'reject' ? $requests->reject($actor, $r, (string) $d['note']) : $requests->withdraw($actor, $r);

            return ['message' => $step === 'reject' ? 'Turned down.' : 'Withdrawn.'];
        });
    }

    private function validated(Request $request, bool $create): array
    {
        $max = min((int) $request->user()->security_level, 6);

        return $request->validate([
            'name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:255'], 'security_level' => ['required', 'integer', 'min:1', "max:{$max}"],
            'is_technical' => [$create ? 'nullable' : 'prohibited', 'boolean'], 'permissions' => ['array'], 'permissions.*' => ['string'], 'reason' => ['nullable', 'string', 'max:255'],
        ], ['name.required' => 'Name the role.', 'security_level.max' => 'You cannot give a level above your own.', 'is_technical.prohibited' => 'A role stays technical or not once added.']);
    }
}
