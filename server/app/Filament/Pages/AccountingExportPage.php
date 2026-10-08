<?php

namespace App\Filament\Pages;

use App\Domain\Finance\AccountingExport;
use App\Filament\Support\Erp;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The books for a period as journals for QuickBooks, Xero or Sage. */
class AccountingExportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpOnSquareStack;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Export to QuickBooks / Xero / Sage';

    protected static ?string $title = 'Export to another accounting program';

    protected static ?string $slug = 'accounting-export';

    protected static ?int $navigationSort = 90;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('finance.view') && Erp::branchIds('finance.view') === null;
    }

    public function mount(): void
    {
        $this->form->fill(['format' => 'quickbooks', 'from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make()->columns(3)->schema([
                Select::make('format')->label('Program')->options(AccountingExport::FORMATS)->required(),
                DatePicker::make('from')->required(),
                DatePicker::make('to')->required(),
                Text::make('Every journal entry posted in the period, line by line, with the account code and name (amounts before tax as booked). Create the same account codes in the other program first, or map them when importing. Dates are day/month/year.')->columnSpanFull(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('download')
                ->footer([Actions::make([Action::make('download')->label('Download the file')->icon(Heroicon::OutlinedArrowDownTray)->submit('download')])]),
        ]);
    }

    public function download()
    {
        $d = $this->form->getState();

        return Erp::run(fn ($actor) => app(AccountingExport::class)->download($actor, $d['format'], $d['from'], $d['to']));
    }
}
