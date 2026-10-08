<?php

namespace App\Filament\Support;

use App\Domain\Catalog\CatalogService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
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

        return $table
            ->pushHeaderActions([CreateAction::make()->using(fn (array $data) => $save(null, $data))])
            ->recordActions([EditAction::make()->using(fn (Model $record, array $data) => $save($record, $data))]);
    }
}
