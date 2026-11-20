<?php

namespace App\Domain\Office;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Modules\ModuleService;
use App\Filament\Pages\ManageModules;
use App\Filament\Pages\ManageSettings;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\User;
use App\Support\Settings;

/**
 * Settings and Modules in the new back office (settings and admin, part 4 of the rewrite), as the current pages:
 * every setting from Settings::DEFINITIONS by group (an industry's group only where that industry runs, a paid
 * add-on's only with its licence), greyed with who changes it when this person may not; and the modules of one branch
 * at a time (enabled, reserved or off), through Settings and ModuleService (SettingsController).
 */
final class SettingsScreen
{
    /** Group => the industry module it belongs to: shown only where that industry runs. */
    public const INDUSTRY_GROUPS = ['Restaurant' => 'restaurant', 'Pharmacy' => 'pharmacy', 'Grocery' => 'grocery', 'Fashion' => 'fashion', 'Services' => 'services', 'Distribution' => 'distribution'];

    /** Group => the paid add-on it belongs to: shown only when the licence has it. */
    public const ADDON_GROUPS = ['Service desk and maintenance' => 'service'];

    public function __construct(private readonly User $user) {}

    public static function canSettings(): bool
    {
        return ManageSettings::canAccess();
    }

    public static function canModules(): bool
    {
        return ManageModules::canAccess();
    }

    /** Is this group shown here (industry running, add-on licensed)? */
    public static function groupShown(string $group): bool
    {
        return (! isset(self::INDUSTRY_GROUPS[$group]) || ModuleService::enabledAnywhere(self::INDUSTRY_GROUPS[$group]))
            && (! isset(self::ADDON_GROUPS[$group]) || app(LicenseManager::class)->hasAddon(self::ADDON_GROUPS[$group]));
    }

    public function settings(): array
    {
        $values = app(Settings::class);
        $groups = [];
        foreach (Settings::DEFINITIONS as $key => $d) {
            if (! self::groupShown($d['group'])) {
                continue;
            }
            $may = Settings::mayChange($key, $this->user);
            $groups[$d['group']][] = [
                'key' => $key, 'label' => $d['label'], 'help' => $d['help'], 'type' => $d['type'], 'value' => $values->get($key, $this->user->tenant_id),
                'options' => isset($d['options']) ? array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($d['options']), $d['options']) : null,
                'min' => $d['min'] ?? null, 'max' => $d['max'] ?? null, 'rows' => $d['rows'] ?? null, 'may_change' => $may,
                'who' => $may ? null : match (Settings::who($key)) {
                    'superadmin' => 'Changed by SyncLytics support', 'technical' => 'Changed by IT', default => 'Changed by the owner'
                },
            ];
        }

        return ['groups' => array_map(fn ($g, $items) => ['group' => $g, 'settings' => $items], array_keys($groups), $groups)];
    }

    /** @return array<string, string> branch id => "CODE · Name", the branches this person may set modules for */
    public static function moduleBranches(): array
    {
        $ids = Erp::branchIds('admin.modules.manage');

        return Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])->all();
    }

    public function modules(?string $branchId): array
    {
        $branches = self::moduleBranches();
        $branchId = isset($branches[(string) $branchId]) ? $branchId : array_key_first($branches);
        $current = $branchId ? app(ModuleService::class)->statuses(Branch::findOrFail($branchId)) : [];
        $sections = [];
        foreach (['industry' => 'Industry modules', 'department' => 'Optional department modules'] as $kind => $label) {
            $mods = [];
            foreach (ModuleService::licensed() as $m) { // only modules the licence covers
                if ($m->kind === $kind) {
                    $mods[] = ['key' => $m->key, 'name' => $m->name, 'description' => $m->description, 'status_label' => $m->statusLabel(), 'available' => $m->isAvailable(),
                        'status' => $current[$m->key] ?? 'disabled'];
                }
            }
            $sections[] = ['label' => $label, 'modules' => $mods];
        }

        return ['branch_id' => $branchId, 'branches' => array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => $v], array_keys($branches), $branches), 'sections' => $sections];
    }
}
