<?php

namespace App\Filament\Pages;

use App\Domain\Catalog\SharedProducts;
use App\Filament\Support\Erp;
use App\Models\Industry;
use App\Models\Product;
use App\Support\IndustryContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/** Products in no industry, with where they are really used, to move them to the right catalogue. */
class SharedProductsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Shared products';

    protected static ?string $title = 'Shared products';

    protected static ?string $slug = 'shared-products';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.table-page';

    /** @var array<string, list<string>>|null */
    private ?array $used = null;

    public static function canAccess(): bool
    {
        $user = Erp::user();

        return $user !== null && Erp::canAnywhere('catalog.manage') && IndustryContext::companyWide($user) && Industry::licensed()->exists();
    }

    public function getSubheading(): ?string
    {
        return 'These products show on every industry\'s tills. Move each to its own industry, and leave only what every shop really sells.';
    }

    private function usedIn(Product $product): array
    {
        $this->used ??= app(SharedProducts::class)->whereUsed(Erp::user()->tenant_id);

        return $this->used[$product->id] ?? [];
    }

    private function moved(int $count): void
    {
        Notification::make()->success()->title("{$count} product(s) moved")->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('moveAll')->label('Move all to one industry')->icon(Heroicon::OutlinedArrowRight)->requiresConfirmation()
                ->modalDescription(fn () => number_format(Product::whereNull('industry_id')->count()).' shared products will move. Their stock and prices stay. You can still move any of them again.')
                ->schema([Select::make('industry')->label('Industry')->required()->options(fn () => Industry::licensed()->orderBy('sort')->pluck('name', 'id'))])
                ->action(function (array $data) {
                    @set_time_limit(1800);
                    $this->moved(Erp::run(fn ($actor) => app(SharedProducts::class)->moveAll($actor, $data['industry'])));
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $names = fn () => Industry::pluck('name', 'id');

        return $table
            ->query(fn () => Product::query()->whereNull('industry_id')->with('category'))
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable(),
                TextColumn::make('name')->searchable()->sortable()->limit(40),
                TextColumn::make('category.name')->label('Category')->placeholder('—'),
                TextColumn::make('used')->label('Stock or sales in')->placeholder('Nowhere yet')
                    ->state(fn (Product $p) => $names()->only($this->usedIn($p))->implode(', ')),
                TextColumn::make('suggested')->label('Suggested industry')->placeholder('Your choice')
                    ->state(fn (Product $p) => $names()->get(SharedProducts::suggestion($this->usedIn($p)))),
            ])
            ->defaultSort('name')
            ->toolbarActions([
                BulkAction::make('moveSuggested')->label('Move to suggested industry')->icon(Heroicon::OutlinedSparkles)->requiresConfirmation()
                    ->modalDescription('Products with no suggestion stay here.')
                    ->action(fn (Collection $records) => $this->moved(Erp::run(fn ($actor) => app(SharedProducts::class)->moveSuggested($actor, $records))))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('No shared products')
            ->emptyStateDescription('Every product belongs to one industry.');
    }
}
