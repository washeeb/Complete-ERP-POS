<?php

namespace App\Filament\Resources\DiningOrders;

use App\Domain\Modules\ModuleService;
use App\Filament\Resources\DiningOrders\Pages\ListDiningOrders;
use App\Filament\Resources\DiningOrders\Pages\ViewDiningOrder;
use App\Filament\Support\Erp;
use App\Models\DiningOrder;
use App\Models\DiningOrderItem;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Restaurant bills: open tables and tabs now, and the history of every order, with voids and who did them. */
class DiningOrderResource extends Resource
{
    protected static ?string $model = DiningOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?string $navigationLabel = 'Bills';

    protected static ?string $modelLabel = 'bill';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('restaurant.manage') || Erp::canAnywhere('restaurant.void')) && ModuleService::enabledAnywhere('restaurant');
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
        $a = Erp::branchIds('restaurant.manage');
        $b = Erp::branchIds('restaurant.void');
        $all = ($a === null && Erp::canAnywhere('restaurant.manage')) || ($b === null && Erp::canAnywhere('restaurant.void'));

        return parent::getEloquentQuery()->with(['table', 'items'])->when(! $all, fn ($q) => $q->whereIn('branch_id', array_merge($a ?? [], $b ?? [])));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('label')->label('Where')->state(fn (DiningOrder $r) => $r->label()),
                TextEntry::make('waiter_name')->label('Waiter'),
                TextEntry::make('status')->badge(),
                TextEntry::make('opened_at')->label('Opened')->dateTime(),
                TextEntry::make('closed_at')->label('Closed')->dateTime()->placeholder('—'),
                TextEntry::make('covers')->label('Guests'),
                TextEntry::make('note')->placeholder('—'),
            ]),
            Section::make('Items')->schema([
                TextEntry::make('item_list')->hiddenLabel()->html()->state(fn (DiningOrder $r) => '<table style="width:100%">'.$r->items->map(fn (DiningOrderItem $i) => '<tr><td>'
                    .e(rtrim(rtrim((string) $i->qty, '0'), '.').' × '.$i->name).'<br><small>'.e(collect($i->modifiers ?? [])->pluck('name')->push($i->note)->filter()->implode(', ')).'</small></td><td>'
                    .e(DiningOrderItem::STATUSES[$i->status] ?? $i->status).($i->status === 'void' ? ' — '.e($i->void_reason).' ('.e($i->voided_by_name).')' : '')
                    .'</td><td>'.($i->sale_id ? 'paid' : '').'</td></tr>')->implode('').'</table>'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('opened_at')->label('Opened')->dateTime()->sortable(),
                TextColumn::make('where')->label('Where')->state(fn (DiningOrder $r) => $r->label()),
                TextColumn::make('waiter_name')->label('Waiter')->searchable(),
                TextColumn::make('status')->badge()->color(fn ($state) => ['open' => 'warning', 'closed' => 'success'][$state] ?? 'gray'),
                TextColumn::make('voids')->label('Voided items')->state(fn (DiningOrder $r) => $r->items->where('status', 'void')->count() ?: null)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Paid', 'void' => 'Cancelled']),
                SelectFilter::make('kind')->options(DiningOrder::KINDS),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDiningOrders::route('/'), 'view' => ViewDiningOrder::route('/{record}')];
    }
}
