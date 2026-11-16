<?php

namespace App\Http\Controllers\Office;

use App\Domain\Access\AccessService;
use App\Domain\Branding\Branding;
use App\Domain\Office\HomeData;
use App\Domain\Office\TodoData;
use App\Domain\Pos\TillApprovals;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TillApproval;
use App\Models\User;
use App\Security\ReconfirmNeeded;
use App\Support\Look;
use App\Support\UserPrefs;
use BackedEnum;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The new back office (owner decision 2026-11-15: a Windows-style app in React + Fluent UI, two looks: menu across the
 * top, or a slim side menu). It is a page served at /office; what it shows comes from here, built from exactly what the
 * current back office offers this person (its menu, permissions, modules and licence), so both always agree. Screens not
 * yet rebuilt open inside it.
 */
class OfficeController extends Controller
{
    /**
     * The app itself, for addresses inside it (/office/a/…); the web server hands out /office/ directly. Signed out: the
     * sign-in page first, then back here.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if (! $request->user()) {
            return $this->signIn($request->merge(['to' => $request->getRequestUri()]));
        }
        $index = public_path('office/index.html');

        return is_file($index) ? response()->file($index, ['Cache-Control' => 'no-cache'])
            : response('The new back office is not installed on this server yet. In the pos folder run: npm run build:office', 404);
    }

    /** Sign in, then come back to this page of the new back office (an address of /office only, never another site). */
    public function signIn(Request $request): RedirectResponse
    {
        $to = (string) $request->query('to', $request->input('to', '/office/'));
        $to = preg_match('#^/office(/[^\s\\\\]*)?$#', $to) && ! str_starts_with($to, '//') ? $to : '/office/';
        if ($request->user()) {
            return redirect($to);
        }
        $request->session()->put('url.intended', rtrim(url('/'), '/').$to); // exactly this address (url() would drop the final /)

        return redirect('/admin/login');
    }

    /** Who is signed in, their company's colours and logo, and their choices. */
    public function me(Request $request): JsonResponse
    {
        $user = $this->panel($request);
        $tenant = Tenant::find($user->tenant_id);
        $brand = Branding::forTenant($tenant);

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'role' => Look::roleLine(), 'photo' => $user->getFilamentAvatarUrl(),
                'initials' => collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('')],
            'company' => ['name' => $brand['company'], 'color' => $brand['color'], 'on_color' => $brand['on_color'], 'menu_color' => Look::menuColor(),
                'logo' => $brand['logo_url'], 'logo_dark' => $brand['logo_dark_url'], 'icon' => $brand['icon_url']],
            'prefs' => UserPrefs::for($user),
            'favourites' => Look::favourites(),
            'classic' => '/admin', // the current back office, for a few releases
        ]);
    }

    /** The menu: areas → sections → (headed) groups → pages, as the current back office has it for this person. */
    public function menu(Request $request): JsonResponse
    {
        $this->panel($request);
        $icon = fn ($i) => $i instanceof BackedEnum ? (string) $i->value : (is_string($i) ? $i : null);
        $tabs = array_map(fn ($t) => ['label' => $t['label'], 'icon' => Look::tabIcon($t['label']),
            'sections' => array_map(fn ($s) => ['label' => $s['label'], 'groups' => array_map(fn ($g) => ['label' => $g['label'],
                'items' => array_map(fn ($i) => ['label' => $i['label'], 'url' => $this->path($i['url']), 'icon' => $icon($i['icon']),
                    'badge' => $i['badge'] === null ? null : (string) $i['badge']], $g['items'])], $s['groups'])], $t['sections'])], Look::tabs());

        $create = [];
        foreach (Look::quickCreate() as $group => $items) {
            $create[] = ['label' => $group, 'items' => array_map(fn ($i) => ['label' => $i['label'], 'url' => $this->path($i['url'])], $items)];
        }

        return response()->json(['home' => $this->path(Look::homeUrl()), 'tabs' => $tabs, 'create' => $create]);
    }

    /** Search records (customers, products, invoices…) the way the current back office's search does. */
    public function search(Request $request): JsonResponse
    {
        $this->panel($request);
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $out = [];
        try {
            foreach (Filament::getGlobalSearchProvider()->getResults($q)?->getCategories() ?? [] as $category => $results) {
                foreach ($results as $r) {
                    $out[] = ['group' => (string) $category, 'title' => strip_tags((string) $r->title), 'url' => $this->path((string) $r->url),
                        'details' => collect($r->details)->map(fn ($v, $k) => "{$k}: ".strip_tags((string) $v))->values()->take(2)->implode(' · ')];
                    if (count($out) >= 30) {
                        break 2;
                    }
                }
            }
        } catch (Throwable) {
            // a search that fails for one kind of record still shows the menu matches (done in the app)
        }

        return response()->json(['results' => $out]);
    }

    /** The person's own choices for the new back office (look: menu across the top, or side menu). */
    public function prefs(Request $request, AccessService $access): JsonResponse
    {
        $user = $this->panel($request);
        $data = $request->validate(['office_look' => ['required', 'string', 'in:'.implode(',', array_keys(UserPrefs::OFFICE_LOOKS))]]);
        try {
            DB::transaction(fn () => $access->updateOwnProfile(Actor::user($user), $user, ['ui_prefs' => ['office_look' => $data['office_look']] + UserPrefs::for($user)]));
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['prefs' => UserPrefs::for($user->fresh())]);
    }

    /** Home: reminders, KPIs for the period chosen (and the one before), top items, the monthly trend (App\Domain\Office\HomeData). */
    public function home(Request $request): JsonResponse
    {
        $user = $this->panel($request);
        $period = (string) $request->query('period', 'month');
        $top = (string) $request->query('top', 'month');
        $ok = array_keys(HomeData::PERIODS);

        return response()->json((new HomeData($user))->build(in_array($period, $ok, true) ? $period : 'month', in_array($top, $ok, true) ? $top : 'month')
            + ['layout' => UserPrefs::for($user)['office_home'] ?? null]);
    }

    /** To-do: everything waiting for this person's decision (App\Domain\Office\TodoData). */
    public function todo(Request $request): JsonResponse
    {
        return response()->json((new TodoData($this->panel($request)))->build());
    }

    /** The number on the To-do icon. */
    public function todoCount(Request $request): JsonResponse
    {
        return response()->json(['count' => (new TodoData($this->panel($request)))->count()]);
    }

    /**
     * A till request answered from To-do. Approving asks the person to confirm it is them first (as on the Till requests
     * page): the answer then says where to confirm, and the app opens it.
     */
    public function decideTillRequest(Request $request, string $id, TillApprovals $approvals): JsonResponse
    {
        $user = $this->panel($request);
        $data = $request->validate(['yes' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $r = TillApproval::findOrFail($id);
        try {
            $r = $approvals->decide(Actor::user($user, 'web'), $r, (bool) $data['yes'], $data['note'] ?? null);
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $r->status, 'decided_by' => $r->decided_by_name]);
    }

    /** The person arranged their Home (Personalize, Layout): kept with their other choices. */
    public function homeLayout(Request $request, AccessService $access): JsonResponse
    {
        $user = $this->panel($request);
        $layout = UserPrefs::cleanHome($request->input('layout'));
        try {
            DB::transaction(fn () => $access->updateOwnProfile(Actor::user($user), $user, ['ui_prefs' => ['office_home' => $layout] + UserPrefs::for($user)]));
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['layout' => UserPrefs::for($user->fresh())['office_home'] ?? null]);
    }

    /** The answers above are the back office's own: same panel, same person, same rules. */
    private function panel(Request $request): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $request->user();
    }

    /** A page of the current back office, as a path on this site (never another site). */
    private function path(string $url): string
    {
        $parts = parse_url($url);
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');

        return Str::startsWith($path, '/admin') ? $path.(isset($parts['query']) ? '?'.$parts['query'] : '') : '/admin';
    }
}
