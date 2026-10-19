<?php

namespace App\Filament\Support;

use App\Domain\Catalog\CatalogService;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Small reference lists (categories, brands, units, tax rates) edited in modals on one page.
 * Saves go through CatalogService so they are permission-checked and audited.
 */
abstract class SimpleCatalogResource extends Resource
{
    protected static string $viewPermission = 'catalog.view';

    protected static string $managePermission = 'catalog.manage';

    /** Unused ones can be deleted (categories, brands, units). */
    protected static bool $deletable = false;

    /** The product field to move products by from a row ("Move products to…"), e.g. category_id. */
    protected static ?string $movesProducts = null;

    public static function canViewAny(): bool
    {
        return Erp::can(static::$viewPermission);
    }

    public static function canCreate(): bool
    {
        return Erp::can(static::$managePermission);
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can(static::$managePermission);
    }

    public static function canDelete(Model $record): bool
    {
        return false; // referenced by products and history; deactivate or rename instead
    }

    /** Wire the standard create/edit modals to the service. */
    public static function crudTable(Table $table): Table
    {
        $save = fn (?Model $record, array $data): Model => Erp::run(fn ($actor) => app(CatalogService::class)
            ->saveSimple($actor, $record ?? new (static::getModel()), $data, static::$managePermission));

        $actions = [EditAction::make()->using(fn (Model $record, array $data) => $save($record, $data))];
        if (static::$movesProducts) {
            // All its products to another one (also how a duplicate is merged: move, then delete the empty one).
            $field = static::$movesProducts;
            $actions[] = Action::make('moveProducts')->label('Move products')->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                ->visible(fn (Model $record) => Erp::can(static::$managePermission) && Product::where($field, $record->getKey())->exists())
                ->modalDescription(fn (Model $record) => Product::where($field, $record->getKey())->count().' product(s) will move. Nothing else about them changes.')
                ->schema([Select::make('to')->label('Move them to')->searchable()->required($field === 'tax_group_id')
                    ->options(fn (Model $record) => static::getModel()::whereKeyNot($record->getKey())->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('None')])
                ->action(function (Model $record, array $data) use ($field) {
                    @set_time_limit(1800);
                    $n = Erp::run(fn ($actor) => app(CatalogService::class)->moveProducts($actor, Product::where($field, $record->getKey())->pluck('id')->all(), $field, $data['to'] ?? null));
                    Notification::make()->success()->title("{$n} product(s) moved")->send();
                });
        }
        if (static::$deletable) {
            $actions[] = Action::make('delete')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                ->visible(fn () => Erp::can(static::$managePermission))
                ->modalDescription('Only possible when nothing uses it; otherwise move its products first.')
                ->action(fn (Model $record) => Erp::run(fn ($actor) => app(CatalogService::class)->deleteSimple($actor, $record, static::$managePermission), 'Deleted'));
        }

        return $table
            ->pushHeaderActions([CreateAction::make()->using(fn (array $data) => $save(null, $data))])
            ->recordActions($actions);
    }
}
