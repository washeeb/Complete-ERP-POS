<?php

namespace App\Filament\Pages;

use App\Domain\Customers\CustomerLedger;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Erp;
use App\Models\Customer;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/** Who owes what, by how late (days past due). */
class AgedReceivables extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Aged receivables';

    protected static ?string $title = 'Aged receivables';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('receivables.view');
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => CustomerResource::money((int) $state);
        $col = fn (string $key, string $label) => TextColumn::make($key)->label($label)->alignEnd()->formatStateUsing($money)
            ->color(fn ($state) => $state > 0 && $key !== 'current' && $key !== 'total' ? 'danger' : null);

        return $table
            ->records(function () {
                $aging = app(CustomerLedger::class)->aging();
                $names = Customer::whereIn('id', array_keys($aging))->get(['id', 'code', 'name', 'phone'])->keyBy('id');

                return collect($aging)->map(fn ($b, $id) => $b + ['key' => $id, 'name' => $names[$id]->name ?? '?', 'code' => $names[$id]->code ?? '', 'phone' => $names[$id]->phone ?? null])
                    ->sortByDesc('total')->all();
            })
            ->columns([
                TextColumn::make('name')->label('Customer')->description(fn (array $record) => trim($record['code'].' '.($record['phone'] ?? '')))
                    ->url(fn (array $record) => CustomerResource::getUrl('view', ['record' => $record['key']])),
                $col('current', 'Not yet due'),
                $col('d1_30', '1–30 days'),
                $col('d31_60', '31–60'),
                $col('d61_90', '61–90'),
                $col('d90_plus', 'Over 90'),
                TextColumn::make('total')->label('Total')->alignEnd()->weight('bold')->formatStateUsing($money),
            ])
            ->emptyStateHeading('Nobody owes anything');
    }
}
