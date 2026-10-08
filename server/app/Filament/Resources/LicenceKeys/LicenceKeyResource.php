<?php

namespace App\Filament\Resources\LicenceKeys;

use App\Domain\Modules\ModuleService;
use App\Domain\Services\LicenceKeyService;
use App\Filament\Resources\LicenceKeys\Pages\ListLicenceKeys;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\LicenceKey;
use App\Models\Product;
use App\Models\Sale;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Software licence keys: loaded per product and branch, handed out one per unit sold (printed on the receipt). */
class LicenceKeyResource extends Resource
{
    protected static ?string $model = LicenceKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Licence keys';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('services.licence_keys') && (ModuleService::enabledAnywhere('services') || ModuleService::enabledAnywhere('electronics'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('services.licence_keys');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('product_id')->label('Product')->formatStateUsing(fn ($state) => Product::whereKey($state)->value('name')),
                TextColumn::make('branch_id')->label('Branch')->formatStateUsing(fn ($state) => Branch::whereKey($state)->value('code')),
                TextColumn::make('hint')->label('Key')->formatStateUsing(fn ($state) => '…'.$state)->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => LicenceKey::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['available' => 'success', 'sold' => 'info'][$state] ?? 'gray'),
                TextColumn::make('sold_at')->label('Sold')->dateTime('j M Y H:i')->placeholder('—')
                    ->description(fn (LicenceKey $r) => $r->sale_id ? Sale::whereKey($r->sale_id)->value('receipt_no') : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(LicenceKey::STATUSES),
                SelectFilter::make('product_id')->label('Product')->options(fn () => Product::where('licence_keys', true)->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([
                Action::make('show')->label('Show key')->color('gray')->modalSubmitAction(false)->modalHeading('Licence key')
                    ->modalDescription(fn (LicenceKey $r) => $r->licence_key),
                Action::make('void')->color('danger')->visible(fn (LicenceKey $r) => $r->status === 'available')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (LicenceKey $r, array $data) => Erp::run(fn ($actor) => app(LicenceKeyService::class)->void($actor, $r, $data['reason']), 'Key voided')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLicenceKeys::route('/')];
    }
}
