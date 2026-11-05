<?php

namespace App\Filament\Pages;

use App\Domain\Fashion\ConsignmentService;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\MiniTable;
use App\Models\Branch;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Purchasing → Consignment to pay: what each supplier is owed for consigned goods sold, in any industry. Settling
 * raises a supplier bill, approved and paid as usual.
 */
class ConsignmentToPay extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Consignment to pay';

    protected static ?string $title = 'Consignment to pay';

    protected static ?string $slug = 'consignment-to-pay';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.consignment-to-pay';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('payables.manage');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Goods on consignment stay the supplier\'s until sold. Each sale adds what is owed here.';
    }

    public function table(): string
    {
        $rows = ConsignmentService::owed();
        $codes = Branch::query()->pluck('code', 'id');

        return MiniTable::html(['Supplier', 'Branch', 'Quantity sold', 'Sold for (before tax)', 'Owed'], $rows->map(fn ($r) => [$r->name, $codes[$r->branch_id] ?? '—',
            rtrim(rtrim((string) $r->qty, '0'), '.'), FinanceUi::money((int) $r->sold_minor), FinanceUi::money((int) $r->owed_minor)])->all(), [], 'Nothing is owed.')->toHtml();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settle')->label('Settle')->icon(Heroicon::OutlinedBanknotes)->visible(fn () => ConsignmentService::owed()->isNotEmpty())
                ->modalDescription('Raises a supplier bill for everything owed up to today. It then goes to Payables, to be approved and paid as usual.')
                ->schema([
                    Select::make('who')->label('Supplier and branch')->required()->options(fn () => ConsignmentService::owed()->mapWithKeys(fn ($r) => ["{$r->consignor_id}|{$r->branch_id}" => $r->name.' · '
                        .(Branch::find($r->branch_id)?->code).' · '.FinanceUi::money((int) $r->owed_minor)])->all()),
                    TextInput::make('statement')->label('Supplier\'s statement or invoice number')->required()->maxLength(60),
                ])
                ->action(function (array $data) {
                    [$supplier, $branch] = explode('|', $data['who']);
                    $bill = Erp::run(fn ($actor) => app(ConsignmentService::class)->settle($actor, Supplier::findOrFail($supplier), Branch::findOrFail($branch), now()->toDateString(), $data['statement']));
                    if ($bill) {
                        Notification::make()->success()->title("Bill {$bill->number} raised")->send();
                    }
                }),
        ];
    }
}
