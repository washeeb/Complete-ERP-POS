<?php

namespace App\Domain\Office;

use App\Domain\Catalog\SharedProducts;
use App\Filament\Pages\SharedProductsPage;
use App\Models\Industry;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared products in the new back office (catalog and pricing setup, part 10 of the rewrite), as the current screen:
 * products in no industry, which every industry's tills sell, with where they really have stock or recent sales and
 * the industry that suggests; Move all to one industry, and Move the ticked ones to their suggested industry, through
 * SharedProducts (CatalogToolsController). Nothing moves unless a person says so. Who may do it is unchanged.
 */
final class SharedProductsScreen
{
    public const PER_PAGE = [25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return SharedProductsPage::canAccess();
    }

    public static function query(): Builder
    {
        return Product::query()->whereNull('industry_id');
    }

    public function industries(): array
    {
        return Industry::licensed()->orderBy('sort')->get()->map(fn ($i) => ['id' => $i->id, 'label' => $i->name])->all();
    }

    /** @param  array<string, mixed>  $p  q, dir, page, per */
    public function list(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 50;
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = self::query()->with('category');
        if (($search = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"));
        }
        $used = app(SharedProducts::class)->whereUsed($this->user->tenant_id);
        $names = Industry::pluck('name', 'id');

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'all' => self::query()->count(), 'industries' => $this->industries(),
            'rows' => $q->orderBy('name', ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc')->orderBy('id')->forPage($page, $per)->get()->map(function (Product $r) use ($used, $names) {
                $in = $used[$r->id] ?? [];
                $suggested = SharedProducts::suggestion($in);

                return ['id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'category' => $r->category?->name,
                    'used' => $names->only($in)->implode(', '), 'suggested' => $suggested ? $names->get($suggested) : null];
            })->all()];
    }
}
