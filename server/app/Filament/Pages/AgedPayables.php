<?php

namespace App\Filament\Pages;

use App\Domain\Payables\SupplierLedger;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Supplier;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/** What we owe each supplier, by how late (days past due). */
class AgedPayables extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Aged payables';

    protected static ?string $title = 'Aged payables';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('payables.view') && config('erp.node.role') !== 'branch';
    }

    protected function getHeaderActions(): array
    {
        return [SupplierPaymentResource::payAction()];
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => FinanceUi::money((int) $state);
        $col = fn (string $key, string $label) => TextColumn::make($key)->label($label)->alignEnd()->formatStateUsing($money)
            ->color(fn ($state) => $state > 0 && $key !== 'current' && $key !== 'total' ? 'danger' : null);

        return $table
            ->records(function () {
                $aging = app(SupplierLedger::class)->aging();
                $names = Supplier::whereIn('id', array_keys($aging))->get(['id', 'code', 'name'])->keyBy('id');

                return collect($aging)->map(fn ($b, $id) => $b + ['key' => $id, 'name' => $names[$id]->name ?? '?', 'code' => $names[$id]->code ?? ''])->sortByDesc('total')->all();
            })
            ->columns([
                TextColumn::make('name')->label('Supplier')->description(fn (array $record) => $record['code']),
                $col('current', 'Not yet due'),
                $col('d1_30', '1–30 days'),
                $col('d31_60', '31–60'),
                $col('d61_90', '61–90'),
                $col('d90_plus', 'Over 90'),
                TextColumn::make('total')->label('Total')->alignEnd()->weight('bold')->formatStateUsing($money),
            ])
            ->emptyStateHeading('Nothing owed to suppliers');
    }
}
