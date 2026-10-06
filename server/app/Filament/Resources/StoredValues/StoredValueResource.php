<?php

namespace App\Filament\Resources\StoredValues;

use App\Domain\Customers\StoredValues;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\StoredValues\Pages\ListStoredValues;
use App\Filament\Support\Erp;
use App\Models\Customer;
use App\Models\StoredValue;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Gift cards and store credit: balances, history, and selling a gift card. */
class StoredValueResource extends Resource
{
    protected static ?string $model = StoredValue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Gift cards & credit';

    protected static ?string $modelLabel = 'gift card';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('giftcards.manage');
    }

    public static function canCreate(): bool
    {
        return false; // "Sell gift card"; store credit comes from returns
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function sellAction(): Action
    {
        return Action::make('sell')->label('Sell gift card')->icon(Heroicon::OutlinedGift)
            ->visible(fn () => Erp::canAnywhere('giftcards.manage'))
            ->schema([
                TextInput::make('amount')->required()->numeric()->minValue(1),
                Select::make('paid_by')->label('Paid with')->required()->options(['cash' => 'Cash', 'momo' => 'Mobile money', 'card' => 'Card', 'bank' => 'Bank transfer', 'free' => 'Given free (promotion)']),
                TextInput::make('reference')->label('Payment reference')->maxLength(30),
                TextInput::make('code')->label('Card number (printed card)')->maxLength(30)->helperText('Leave blank to generate one.'),
                Select::make('customer_id')->label('For customer (optional)')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::where('name', 'ilike', "%{$search}%")->limit(20)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name),
            ])
            ->action(function (array $data) {
                $card = Erp::run(fn ($actor) => app(StoredValues::class)->sellCard($actor, Money::toMinor((string) $data['amount'], CustomerResource::currency()),
                    $data['paid_by'], $data['reference'] ?? null, isset($data['customer_id']) ? Customer::find($data['customer_id']) : null, $data['code'] ?? null));
                Notification::make()->success()->persistent()->title("Gift card {$card->code}")->body('Balance '.CustomerResource::money($card->balanceMinor()))->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('kind')->badge()->formatStateUsing(fn ($state) => StoredValue::KINDS[$state] ?? $state),
                TextColumn::make('customer.name')->label('Customer')->placeholder('—'),
                TextColumn::make('balance')->label('Balance')->alignEnd()->state(fn (StoredValue $record) => CustomerResource::money($record->balanceMinor())),
                TextColumn::make('last_used_at')->label('Last used')->since()->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('kind')->options(StoredValue::KINDS)])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('history')->label('History')->icon(Heroicon::OutlinedClock)->color('gray')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema(fn (StoredValue $record) => [Text::make(new HtmlString('<table class="w-full text-sm">'
                        .DB::table('stored_value_ledger')->where('stored_value_id', $record->id)->orderBy('occurred_at')->get()
                            ->map(fn ($e) => '<tr><td>'.e(substr($e->occurred_at, 0, 16)).'</td><td>'.e($e->type).'</td><td>'.e($e->doc_number).'</td><td class="text-right">'
                                .e(CustomerResource::money((int) $e->amount_minor)).'</td></tr>')->implode('').'</table>'))]),
            ])
            ->headerActions([self::sellAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStoredValues::route('/')];
    }
}
