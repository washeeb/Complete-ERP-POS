<?php

namespace App\Filament\Pages;

use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * The month's withholding tax: every payment that kept tax back, with the supplier's TIN, the base, the rate and the
 * tax, the total to pay GRA by the 15th of the next month, and a CSV for the GRA schedule and the accountant.
 */
class WithholdingTaxReturn extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Withholding tax';

    protected static ?string $title = 'Withholding tax';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.pages.table-page';

    public string $month = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('payables.view') && config('erp.node.role') !== 'branch';
    }

    public function mount(): void
    {
        $this->month = now()->subMonth()->format('Y-m'); // the return due this month is for last month
    }

    /** @return Collection<int, SupplierPayment> */
    private function payments()
    {
        [$y, $m] = array_map('intval', explode('-', $this->month));

        return SupplierPayment::with('supplier')->where('wht_minor', '>', 0)->whereYear('paid_on', $y)->whereMonth('paid_on', $m)->orderBy('paid_on')->orderBy('number')->get();
    }

    public function getSubheading(): ?string
    {
        $total = (int) $this->payments()->sum('wht_minor');
        $due = Carbon::parse("{$this->month}-01")->addMonth()->day(15)->format('j M Y');

        return 'Total kept back: '.FinanceUi::money($total)." — pay it to GRA by {$due} (from Finance → Bank, against the Withholding tax payable account).";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('month')->label(fn () => Carbon::parse("{$this->month}-01")->format('F Y'))->icon(Heroicon::OutlinedCalendar)->color('gray')
                ->schema([Select::make('month')->label('Month')->required()->default(fn () => $this->month)
                    ->options(collect(range(0, 23))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => now()->startOfMonth()->subMonths($i)->format('F Y')])->all())])
                ->action(fn (array $data) => $this->month = $data['month']),
            Action::make('csv')->label('Download for GRA (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->action(fn () => $this->csv()),
        ];
    }

    private function csv(): StreamedResponse
    {
        $rows = $this->payments();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Certificate', 'Payment', 'Supplier', 'Supplier TIN', 'Nature', 'Amount before VAT', 'Rate %', 'Withholding tax', 'Paid to supplier']);
            foreach ($rows as $p) {
                fputcsv($out, [$p->paid_on->toDateString(), $p->wht_certificate_no, $p->number, $p->supplier?->name, $p->supplier?->tax_no,
                    Supplier::SUPPLY_KINDS[$p->supplier?->supply_kind] ?? '', number_format($p->wht_base_minor / 100, 2, '.', ''),
                    $p->wht_rate_bp / 100, number_format($p->wht_minor / 100, 2, '.', ''), number_format($p->cashMinor() / 100, 2, '.', '')]);
            }
            fclose($out);
        }, "withholding-tax-{$this->month}.csv", ['Content-Type' => 'text/csv']);
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => FinanceUi::money((int) $state);

        return $table
            ->records(fn () => $this->payments()->mapWithKeys(fn (SupplierPayment $p) => [$p->id => [
                'key' => $p->id, 'date' => $p->paid_on->format('j M Y'), 'certificate' => $p->wht_certificate_no, 'number' => $p->number,
                'supplier' => $p->supplier?->name, 'tin' => $p->supplier?->tax_no ?: 'no TIN', 'nature' => Supplier::SUPPLY_KINDS[$p->supplier?->supply_kind] ?? '',
                'base' => (int) $p->wht_base_minor, 'rate' => $p->wht_rate_bp / 100, 'wht' => (int) $p->wht_minor, 'id' => $p->id,
            ]])->all())
            ->columns([
                TextColumn::make('date')->label('Date'),
                TextColumn::make('certificate')->label('Certificate')->description(fn (array $record) => $record['number'])
                    ->url(fn (array $record) => route('print.wht', $record['id']), shouldOpenInNewTab: true),
                TextColumn::make('supplier')->label('Supplier')->description(fn (array $record) => $record['tin'])
                    ->color(fn (array $record) => $record['tin'] === 'no TIN' ? 'danger' : null),
                TextColumn::make('nature')->label('Nature'),
                TextColumn::make('base')->label('Before VAT')->alignEnd()->formatStateUsing($money),
                TextColumn::make('rate')->label('Rate')->alignEnd()->formatStateUsing(fn ($state) => "{$state} %"),
                TextColumn::make('wht')->label('Withholding tax')->alignEnd()->weight('bold')->formatStateUsing($money),
            ])
            ->emptyStateHeading('No withholding tax kept back this month')
            ->emptyStateDescription('Switch it on under Settings → Withholding tax; each supplier payment then keeps it back.');
    }
}
