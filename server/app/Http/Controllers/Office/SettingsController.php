<?php

namespace App\Http\Controllers\Office;

use App\Domain\Modules\ModuleService;
use App\Domain\Office\SettingsScreen;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Security\Reconfirm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings and Modules in the new back office (App\Domain\Office\SettingsScreen), as the current pages. Only settings
 * this person may change, in a group shown here, and actually changed are saved; changing when to confirm payments asks
 * "confirm it's you" first. Modules are set for one branch this person may manage, enabling before turning off.
 */
class SettingsController extends Controller
{
    use ActsOnAccess;

    public function settings(Request $request): JsonResponse
    {
        $this->allowed(SettingsScreen::canSettings());

        return response()->json((new SettingsScreen($request->user()))->settings());
    }

    public function saveSettings(Request $request, Settings $settings): JsonResponse
    {
        $this->allowed(SettingsScreen::canSettings());
        $d = $request->validate(['values' => ['required', 'array'], 'reason' => ['nullable', 'string', 'max:255']]);
        $user = $request->user();
        $changes = collect($d['values'])->filter(fn ($v, $key) => isset(Settings::DEFINITIONS[$key]) && SettingsScreen::groupShown(Settings::DEFINITIONS[$key]['group'])
            && $v != $settings->get($key, $user->tenant_id));
        $refused = $changes->keys()->reject(fn ($key) => Settings::mayChange($key, $user));
        if ($refused->isNotEmpty()) {
            return response()->json(['error' => 'not_yours', 'detail' => 'You may not change: '.$refused->map(fn ($k) => Settings::DEFINITIONS[$k]['label'])->implode(', ').'.'], 403);
        }

        return $this->act(function (Actor $actor) use ($changes, $settings, $d) {
            if ($changes->has(Reconfirm::SETTING)) { // it protects money: a stolen session must not raise it first
                Reconfirm::require($actor, null, 'changing when to confirm payments', always: true);
            }
            foreach ($changes as $key => $value) {
                $settings->set($actor, $key, $value, $d['reason'] ?? null);
            }

            return ['saved' => $changes->count(), 'message' => $changes->isEmpty() ? 'Nothing changed.' : "Settings saved ({$changes->count()} changed)."];
        });
    }

    public function modules(Request $request): JsonResponse
    {
        $this->allowed(SettingsScreen::canModules());

        return response()->json((new SettingsScreen($request->user()))->modules($request->query('branch')));
    }

    public function saveModules(Request $request, ModuleService $modules): JsonResponse
    {
        $this->allowed(SettingsScreen::canModules());
        $d = $request->validate(['branch_id' => ['required', 'uuid'], 'modules' => ['required', 'array'], 'modules.*' => ['in:enabled,reserved,disabled'], 'reason' => ['nullable', 'string', 'max:255']]);
        abort_unless(isset(SettingsScreen::moduleBranches()[$d['branch_id']]), 403, 'You cannot set modules for that branch.');
        $branch = Branch::findOrFail($d['branch_id']);
        $licensed = collect(ModuleService::licensed())->pluck('key')->all();
        $current = $modules->statuses($branch);
        $ordered = collect($d['modules'])->filter(fn ($status, $key) => in_array($key, $licensed, true) && ($current[$key] ?? 'disabled') !== $status)
            ->sortBy(fn ($status) => $status === 'disabled' ? 1 : 0); // enable or reserve before turning off, so what depends on what resolves

        return $this->act(function (Actor $actor) use ($modules, $branch, $ordered, $d) {
            foreach ($ordered as $key => $status) {
                $modules->setStatus($actor, $branch, $key, $status, $d['reason'] ?? null);
            }

            return ['message' => $ordered->isEmpty() ? 'Nothing changed.' : "Modules updated for {$branch->name}."];
        });
    }
}
