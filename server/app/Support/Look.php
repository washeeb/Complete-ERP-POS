<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Throwable;

/**
 * How the back office looks for the person signed in (interface stage 1a):
 *   - classic: today's layout (side menu);
 *   - top: the new look — the menu as tabs across the top, each opening a panel of its sections, like a desktop
 *     program; denser lists and forms;
 *   - side: the new look with the side menu kept.
 * The person's own choice (profile) wins; otherwise the company's (System → Settings → Appearance), the tabs by default
 * (owner 2026-11-02). Phones always get the side menu (the tabs do not fit).
 */
final class Look
{
    public const DEFAULT = 'top';

    public const LOOKS = [
        'classic' => 'Classic (side menu)',
        'top' => 'New look: tabs across the top',
        'side' => 'New look: side menu',
    ];

    /** The top tabs and the menu sections each one holds, in order. A section not listed goes under "More". */
    public const TABS = [
        'Insights' => ['Insights', 'Reports'],
        'Sales' => ['Sales', 'CRM', 'Projects', 'Service desk', 'Cash', 'Online'],
        'Purchasing' => ['Purchasing'],
        'Stock' => ['Stock', 'Catalog', 'Pricing & tax', 'Maintenance', 'Sensors'],
        'Finance' => ['Finance'],
        'People' => ['HR & payroll', 'People & access'],
        'Industries' => ['Grocery', 'Restaurant', 'Pharmacy', 'Electronics', 'Fashion'],
        'Setup' => ['Company', 'Messages', 'System'],
    ];

    /** Words people type for pages named otherwise (the menu search and the page finder). */
    public const MENU_WORDS = ['po' => ['purchase order'], 'grn' => ['goods received'], 'momo' => ['mobile money', 'till shifts'],
        'adjust' => ['stock adjustment'], 'expiry' => ['batches & expiry', 'expiry & returns'], 'staff' => ['users'],
        'password' => ['users'], 'pin' => ['users'], 'vat' => ['tax', 'e-vat'], 'reorder' => ['what to order', 'reorder levels'],
        'count' => ['stock count'], 'transfer' => ['stock transfer'], 'receipt' => ['sales'], 'rfq' => ['requests for quotation'],
        'quote' => ['requests for quotation'], 'contract' => ['contracts and blanket orders'], 'photo' => ['profile'],
        'avatar' => ['profile'], 'dark' => ['profile'], 'theme' => ['profile'],
        'blood' => ['health checks'], 'bp' => ['health checks'], 'pressure' => ['health checks'], 'sugar' => ['health checks'],
        'refund' => ['returns', 'back-office returns'], 'return' => ['returns', 'back-office returns'], 'quarantine' => ['returns'],
        'price list' => ['price list'], 'prices' => ['price list'],
        'invoice' => ['direct purchases'], 'direct' => ['direct purchases'], 'consignment' => ['direct purchases', 'consignment to pay'],
        'cost' => ['cost runs', 'estimated costs'], 'revalue' => ['cost runs'], 'margin' => ['cost runs'],
        'lead' => ['leads'], 'deal' => ['deals', 'pipeline'], 'prospect' => ['leads'], 'follow' => ['follow-ups'], 'campaign' => ['campaigns'], 'crm' => ['pipeline'],
        'job' => ['projects'], 'timesheet' => ['my time'], 'hours' => ['my time'], 'timer' => ['my time'],
        'ticket' => ['tickets'], 'complaint' => ['tickets'], 'helpdesk' => ['tickets'], 'warranty' => ['tickets'], 'repair' => ['work orders'],
        'generator' => ['equipment'], 'asset' => ['equipment'], 'breakdown' => ['work orders'], 'servicing' => ['work orders'],
        'fridge' => ['sensors'], 'temperature' => ['sensors', 'temperature log'], 'cold chain' => ['sensors'], 'alarm' => ['sensor alarms']];

    public static function for(?User $user): string
    {
        $own = $user?->ui_look;
        if ($own !== null && isset(self::LOOKS[$own])) {
            return $own;
        }
        try {
            $company = (string) app(Settings::class)->get('ui.look', $user?->tenant_id);
        } catch (Throwable) {
            $company = self::DEFAULT;
        }

        return isset(self::LOOKS[$company]) ? $company : self::DEFAULT;
    }

    public static function current(): string
    {
        $user = auth()->user();

        return self::for($user instanceof User ? $user : null);
    }

    public static function isNew(): bool
    {
        return self::current() !== 'classic';
    }

    public static function hasTopTabs(): bool
    {
        return self::current() === 'top';
    }

    /** Classes for the page's root element, so the look is right from the first paint (no flash of the old one). */
    public static function htmlClasses(): string
    {
        return match (self::current()) {
            'top' => 'zx-new zx-top',
            'side' => 'zx-new zx-side',
            default => 'zx-classic',
        };
    }

    /**
     * The tabs with their sections and pages, from the menu the person may see (so access rules apply as in the side
     * menu). Pages without a section (the home page) come first, as "Home".
     *
     * @return list<array{label: string, active: bool, sections: list<array{label: string, items: list<array{label: string, url: string, active: bool, icon: mixed, badge: mixed}>}>}>
     */
    public static function tabs(): array
    {
        // Built once per page: the tab band and the page finder both use it.
        return app(RequestMemo::class)->remember('look.tabs.'.auth()->id(), fn () => self::buildTabs());
    }

    /** The icon of each top-level group in the side menu (the tabs' names; an industry tab takes the shop icon). */
    public const TAB_ICONS = ['Home' => 'heroicon-o-home', 'Insights' => 'heroicon-o-chart-bar', 'Sales' => 'heroicon-o-shopping-bag',
        'Purchasing' => 'heroicon-o-shopping-cart', 'Stock' => 'heroicon-o-archive-box', 'Finance' => 'heroicon-o-banknotes',
        'People' => 'heroicon-o-user-group', 'Setup' => 'heroicon-o-cog-6-tooth', 'More' => 'heroicon-o-squares-2x2'];

    public static function tabIcon(string $tab): string
    {
        return self::TAB_ICONS[$tab] ?? 'heroicon-o-building-storefront';
    }

    /** The icon of the page being shown (from the menu), for the page's banner; null when the page is not in the menu. */
    public static function pageIcon(): mixed
    {
        foreach (self::tabs() as $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['items'] as $item) {
                    if ($item['active'] && $item['icon']) {
                        return $item['icon'];
                    }
                }
            }
        }

        return null;
    }

    /** The company's own menu colour (Company → Branding), or null for a dark shade of its brand colour. */
    public static function menuColor(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        $color = app(RequestMemo::class)->remember('look.menu.'.$user->tenant_id, fn () => Tenant::whereKey($user->tenant_id)->value('brand_menu_color'));

        return is_string($color) && preg_match('/^#[0-9A-F]{6}$/i', $color) ? $color : null;
    }

    /** The home page (the house icon on the band). */
    public static function homeUrl(): string
    {
        return (string) Filament::getCurrentPanel()?->getUrl();
    }

    /** The person's favourite pages (the star on the band). @return list<array{url: string, label: string}> */
    public static function favourites(): array
    {
        $user = auth()->user();

        return $user instanceof User ? array_values($user->ui_favourites ?? []) : [];
    }

    /** "Owner", "Cashier +1": the person's role, shown under their name at the top right. */
    public static function roleLine(): string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return '';
        }
        $roles = $user->roleAssignments()->active()->with('role')->get()->pluck('role.name')->filter()->unique()->values();

        return $roles->isEmpty() ? '' : $roles[0].($roles->count() > 1 ? ' +'.($roles->count() - 1) : '');
    }

    private static function buildTabs(): array
    {
        $sectionsByGroup = [];
        $home = [];
        /** @var NavigationGroup $group */
        foreach (Filament::getNavigation() as $group) {
            $items = array_values(array_map(fn (NavigationItem $i) => ['label' => (string) $i->getLabel(), 'url' => (string) $i->getUrl(),
                'active' => $i->isActive(), 'icon' => $i->getIcon(), 'badge' => $i->getBadge()], collect($group->getItems())->all()));
            if ($items === []) {
                continue;
            }
            $label = (string) $group->getLabel();
            if ($label === '') {
                $home = array_merge($home, $items);
            } else {
                $sectionsByGroup[$label] = ['label' => $label, 'items' => $items];
            }
        }

        // The home page has its own house icon on the band; other pages without a section (e.g. Today) go first in the
        // first tab as "Overview", or in a Home tab of their own when the person has no other tab to hold them.
        $home = array_values(array_filter($home, fn ($i) => $i['url'] !== self::homeUrl()));
        $overview = $home === [] ? [] : ['label' => 'Overview', 'items' => $home];

        $tabs = [];
        $placed = [];
        foreach (self::TABS as $tab => $groups) {
            $sections = [];
            foreach ($groups as $g) {
                if (isset($sectionsByGroup[$g])) {
                    $sections[] = $sectionsByGroup[$g];
                    $placed[$g] = true;
                }
            }
            if ($sections !== []) {
                // A tab holding a single section takes its name (e.g. a pharmacy sees "Pharmacy", not "Industries").
                $tabs[] = ['label' => count($sections) === 1 && $tab === 'Industries' ? $sections[0]['label'] : $tab, 'sections' => $sections];
            }
        }
        $rest = array_values(array_diff_key($sectionsByGroup, $placed));
        if ($rest !== []) {
            $tabs[] = ['label' => 'More', 'sections' => $rest];
        }
        if ($overview !== [] && $tabs === []) {
            $tabs[] = ['label' => 'Home', 'sections' => [$overview]];
        } elseif ($overview !== []) {
            array_unshift($tabs[0]['sections'], $overview);
        }

        return array_map(fn ($t) => $t + ['active' => collect($t['sections'])->flatMap(fn ($s) => $s['items'])->contains('active', true)], $tabs);
    }

    /**
     * "+ New": every record the person may create from the menu they see (a product, an order, a customer…), by the
     * menu section it belongs to.
     *
     * @return array<string, list<array{label: string, url: string}>>
     */
    public static function quickCreate(): array
    {
        $out = [];
        foreach (Filament::getCurrentPanel()?->getResources() ?? [] as $resource) {
            try {
                if (! $resource::hasPage('create') || ! $resource::shouldRegisterNavigation() || ! $resource::canAccess() || ! $resource::canCreate()) {
                    continue;
                }
                $group = (string) ($resource::getNavigationGroup() ?? 'Home');
                $out[$group][] = ['label' => ucfirst((string) $resource::getModelLabel()), 'url' => $resource::getUrl('create')];
            } catch (Throwable) {
                continue; // a resource that cannot answer for this person is simply not offered
            }
        }
        $order = array_flip(array_merge(...array_values(self::TABS)));
        uksort($out, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99) ?: strcmp($a, $b));

        return array_map(fn ($l) => collect($l)->sortBy('label')->values()->all(), $out);
    }
}
