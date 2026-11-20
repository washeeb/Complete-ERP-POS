<?php

namespace App\Http\Controllers\Office;

use App\Domain\Access\AccessService;
use App\Domain\Access\SentForApproval;
use App\Domain\Office\UsersScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\Role;
use App\Models\User;
use App\Security\ReconfirmNeeded;
use App\Security\ScopeType;
use App\Support\StaffCard;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Users in the new back office (App\Domain\Office\UsersScreen): the list, one user, Add and Change, till PIN, staff
 * card, two-step sign-in reset, archive and restore, and access grants, through AccessService, as the current screens.
 * A change an IT Admin makes is sent to the owner or SyncLytics support for approval: the request is kept (the
 * transaction commits) and the answer says so, with nothing changed yet.
 */
class UsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed(UsersScreen::canView());

        return response()->json((new UsersScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed(UsersScreen::canView());

        return response()->json((new UsersScreen($request->user()))->options());
    }

    public function show(Request $request, string $user): JsonResponse
    {
        $this->allowed(UsersScreen::canView());
        $screen = new UsersScreen($request->user());

        return response()->json(['user' => $screen->show($screen->find($user) ?? abort(404))]);
    }

    public function store(Request $request, AccessService $access): JsonResponse
    {
        $this->allowed(UsersScreen::canView());
        abort_unless(UsersScreen::canManage(), 403, 'You cannot add users.');
        $d = $this->validated($request, true);

        return $this->act(function (Actor $actor) use ($access, $d) {
            $u = $access->createUser($actor, $d);

            return ['user' => ['id' => $u->id, 'open' => "/office/users/{$u->id}"], 'message' => "{$u->name} added. Grant their access next."];
        });
    }

    public function update(Request $request, string $user, AccessService $access): JsonResponse
    {
        $u = $this->editable($request, $user);
        $d = $this->validated($request, false, $u);
        $reason = $d['reason'] ?? null;
        unset($d['reason']);
        if (blank($d['password'] ?? null)) {
            unset($d['password']);
        }

        return $this->act(fn (Actor $actor) => ['message' => ($access->updateUser($actor, $u, $d, $reason))->name.' saved.']);
    }

    public function pin(Request $request, string $user, AccessService $access): JsonResponse
    {
        $u = $this->editable($request, $user);
        $d = $request->validate(['pin' => ['required', 'regex:/^\d{4,8}$/']], ['pin.*' => 'A PIN is 4 to 8 digits.']);

        return $this->act(function (Actor $actor) use ($access, $u, $d) {
            $access->setPin($actor, $u, $d['pin']);

            return ['message' => 'PIN set. Tell the person privately.'];
        });
    }

    /** A new staff card: the print page opens once, for the person who asked. */
    public function card(Request $request, string $user): JsonResponse
    {
        $u = $this->editable($request, $user);
        $d = $request->validate(['layout' => ['required', Rule::in(array_keys(StaffCard::LAYOUTS))]]);

        return $this->act(fn (Actor $actor) => ['print' => StaffCard::issue($actor, [$u], $d['layout']), 'message' => 'The card is ready to print.']);
    }

    public function cardLost(Request $request, string $user, AccessService $access): JsonResponse
    {
        $u = $this->editable($request, $user);

        return $this->act(function (Actor $actor) use ($access, $u) {
            $access->revokeBadge($actor, $u);

            return ['message' => 'Card stopped.'];
        });
    }

    public function resetMfa(Request $request, string $user, AccessService $access): JsonResponse
    {
        $u = $this->editable($request, $user);
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why (for the audit log).']);

        return $this->act(function (Actor $actor) use ($access, $u, $d) {
            $access->resetMfa($actor, $u, $d['reason']);

            return ['message' => 'Two-step sign-in reset.'];
        });
    }

    public function archive(Request $request, string $user, ArchiveService $archive): JsonResponse
    {
        $this->allowed(UsersScreen::canView());
        $u = (new UsersScreen($request->user()))->find($user) ?? abort(404);
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);

        return $this->act(function (Actor $actor) use ($archive, $u, $d) {
            $archive->archive($actor, $u, $d['reason']);

            return ['message' => "{$u->name} archived."];
        });
    }

    public function restore(Request $request, string $user, ArchiveService $archive): JsonResponse
    {
        $this->allowed(UsersScreen::canView());
        $u = (new UsersScreen($request->user()))->find($user) ?? abort(404);

        return $this->act(function (Actor $actor) use ($archive, $u) {
            $archive->restore($actor, $u);

            return ['message' => "{$u->name} restored."];
        });
    }

    /** Grant a role, company-wide or for one place, optionally with its own limits and an end date. */
    public function grant(Request $request, string $user, AccessService $access): JsonResponse
    {
        $this->allowed(UsersScreen::canView());
        abort_unless(UsersScreen::canGrant(), 403, 'You cannot grant access.');
        $u = (new UsersScreen($request->user()))->find($user) ?? abort(404);
        $d = $request->validate([
            'role_id' => ['required', 'uuid'], 'scope_type' => ['required', Rule::enum(ScopeType::class)], 'scope_id' => ['nullable', 'uuid', 'required_unless:scope_type,tenant'],
            'limit_po' => ['nullable', 'numeric', 'min:0'], 'limit_expense' => ['nullable', 'numeric', 'min:0'], 'limit_credit' => ['nullable', 'numeric', 'min:0'],
            'limit_discount' => ['nullable', 'numeric', 'min:0', 'max:100'], 'expires_at' => ['nullable', 'date', 'after:now'], 'reason' => ['nullable', 'string', 'max:255', 'required_with:expires_at'],
        ], ['role_id.required' => 'Choose the role.', 'scope_id.required_unless' => 'Choose which one it applies to.', 'reason.required_with' => 'Say why: it is needed for temporary access.',
            'expires_at.after' => 'The end must be in the future.']);
        $role = Role::find($d['role_id']) ?? abort(422, 'Choose the role.');
        $type = ScopeType::from($d['scope_type']);
        $limits = array_filter([
            'po_approve_minor' => filled($d['limit_po'] ?? null) ? FinanceUi::toMinor((string) $d['limit_po']) : null,
            'expense_approve_minor' => filled($d['limit_expense'] ?? null) ? FinanceUi::toMinor((string) $d['limit_expense']) : null,
            'credit_override_minor' => filled($d['limit_credit'] ?? null) ? FinanceUi::toMinor((string) $d['limit_credit']) : null,
            'discount_bp' => filled($d['limit_discount'] ?? null) ? (int) round((float) $d['limit_discount'] * 100) : null,
        ], fn ($v) => $v !== null);

        return $this->act(function (Actor $actor) use ($access, $u, $role, $type, $d, $limits) {
            $access->grant($actor, $u, $role, $type, $type === ScopeType::Tenant ? $actor->tenantId : $d['scope_id'], $limits,
                filled($d['expires_at'] ?? null) ? Carbon::parse($d['expires_at']) : null, $d['reason'] ?? null);

            return ['message' => "{$role->name} granted to {$u->name}."];
        });
    }

    public function revoke(Request $request, string $user, string $grant, AccessService $access): JsonResponse
    {
        $this->allowed(UsersScreen::canView());
        abort_unless(UsersScreen::canGrant(), 403, 'You cannot revoke access.');
        $u = (new UsersScreen($request->user()))->find($user) ?? abort(404);
        $g = $u->roleAssignments()->whereKey($grant)->first() ?? abort(404);
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why.']);

        return $this->act(function (Actor $actor) use ($access, $g, $d) {
            $access->revoke($actor, $g, $d['reason']);

            return ['message' => 'Access revoked.'];
        });
    }

    private function editable(Request $request, string $id): User
    {
        $this->allowed(UsersScreen::canView());
        $u = (new UsersScreen($request->user()))->find($id) ?? abort(404);
        abort_unless(UsersScreen::canEdit($u), 403, 'You cannot change this user.');

        return $u;
    }

    private function validated(Request $request, bool $create, ?User $u = null): array
    {
        $max = min((int) $request->user()->security_level, 6);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'username' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9._-]+$/i'],
            'email' => ['required', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'signin_method' => ['required', Rule::in(array_keys(User::SIGNIN_METHODS))],
            'security_level' => ['required', 'integer', 'min:1', $u && $u->id === $request->user()->id ? 'max:7' : "max:{$max}"],
            'password' => [$create ? 'required' : 'nullable', 'string', Password::min(12)],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['username.regex' => 'Letters, numbers, dots, dashes and underscores only.', 'security_level.max' => 'You cannot give a level above your own.',
            'password.required' => 'Give a password (at least 12 characters).']);
    }

    private function act(callable $fn): JsonResponse
    {
        try {
            return response()->json(DB::transaction(function () use ($fn) {
                try {
                    return $fn(Actor::user(request()->user(), 'web'));
                } catch (SentForApproval $e) { // the request is kept: the transaction commits
                    return ['pending' => true, 'message' => $e->request->summary.' The owner or SyncLytics support approves it (People & access → Access requests); it lapses after '
                        .AccessRequest::HOURS.' hours if not answered.'];
                }
            }));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(bool $can): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless($can, 403, 'You cannot see this.');
    }
}
