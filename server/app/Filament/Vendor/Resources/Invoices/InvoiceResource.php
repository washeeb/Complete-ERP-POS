<?php

namespace App\Filament\Vendor\Resources\Invoices;

use App\Filament\Vendor\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Vendor\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Vendor\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorInvoice;
use App\Models\Vendor\VendorPayment;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Licence invoices and the payments against them (ABSA, MoMo, Telecel Cash, cash, card). */
class InvoiceResource extends Resource
{
    protected static ?string $model = VendorInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $modelLabel = 'invoice';

    protected static ?string $recordTitleAttribute = 'number';

    public static function canEdit(Model $record): bool
    {
        return false; // record payments or void it
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                Select::make('customer_id')->label('Customer')->required()->searchable()->options(fn () => VendorCustomer::orderBy('name')->pluck('name', 'id')),
                Select::make('currency')->options(['GHS' => 'GHS', 'USD' => 'USD'])->default('GHS')->required(),
                TextInput::make('due_days')->label('Pay within (days)')->numeric()->minValue(0)->default(14),
            ]),
            Repeater::make('lines')->columns(3)->minItems(1)->defaultItems(1)->schema([
                TextInput::make('description')->required()->maxLength(255)->columnSpan(1),
                TextInput::make('qty')->numeric()->minValue(0.01)->default(1)->required(),
                TextInput::make('unit')->label('Unit price')->numeric()->minValue(0)->required(),
            ]),
            Textarea::make('notes')->maxLength(500),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state, VendorInvoice $r) => $state === 'open' && $r->due_on->isPast() ? 'overdue' : $state)
                    ->color(fn ($state, VendorInvoice $r) => match (true) {
                        $state === 'paid' => 'success', $state === 'void' => 'gray', $r->due_on->isPast() => 'danger', default => 'warning'
                    }),
                TextEntry::make('customer.name')->label('Customer'),
                TextEntry::make('license_id')->label('Licence')->placeholder('—')->formatStateUsing(fn ($state) => strtoupper(substr(str_replace('-', '', $state), 0, 8)))
                    ->url(fn (VendorInvoice $r) => $r->license_id ? LicenseResource::getUrl('view', ['record' => $r->license_id]) : null),
                TextEntry::make('issued_on')->date('j M Y'),
                TextEntry::make('due_on')->date('j M Y'),
                TextEntry::make('total_minor')->label('Total')->formatStateUsing(fn ($state, VendorInvoice $r) => VendorUi::money((int) $state, $r->currency)),
                TextEntry::make('balance')->label('Still owed')->state(fn (VendorInvoice $r) => VendorUi::money($r->balanceMinor(), $r->currency)),
                TextEntry::make('void_reason')->label('Voided')->visible(fn (VendorInvoice $r) => $r->status === 'void'),
                TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Lines and payments')->schema([
                TextEntry::make('detail')->hiddenLabel()->html()->state(function (VendorInvoice $r) {
                    $rows = collect($r->lines)->map(fn ($l) => '<tr><td>'.e($l['description']).'</td><td style="text-align:right">'.e($l['qty']).'</td><td style="text-align:right">'
                        .e(VendorUi::money($l['unit_minor'], $r->currency)).'</td><td style="text-align:right">'.e(VendorUi::money($l['total_minor'], $r->currency)).'</td></tr>')->implode('');
                    $pays = $r->payments->map(fn ($p) => '<li>'.e($p->received_on->format('j M Y').' · '.(VendorPayment::METHODS[$p->method] ?? $p->method).' · '
                        .VendorUi::money($p->amount_minor, $r->currency).($p->reference ? " · {$p->reference}" : '')." · {$p->recorded_by}").'</li>')->implode('');

                    return new HtmlString('<table style="width:100%;font-size:.9rem">'.$rows.'</table><p style="margin-top:8px"><strong>Payments</strong></p><ul>'.($pays ?: '<li>none</li>').'</ul>');
                }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('issued_on', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('issued_on')->date('j M Y')->sortable(),
                TextColumn::make('due_on')->date('j M Y')->sortable(),
                TextColumn::make('total_minor')->label('Total')->formatStateUsing(fn ($state, VendorInvoice $r) => VendorUi::money((int) $state, $r->currency)),
                TextColumn::make('balance')->label('Owed')->state(fn (VendorInvoice $r) => VendorUi::money($r->balanceMinor(), $r->currency)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state, VendorInvoice $r) => $state === 'open' && $r->due_on->isPast() ? 'overdue' : $state)
                    ->color(fn ($state, VendorInvoice $r) => match (true) {
                        $state === 'paid' => 'success', $state === 'void' => 'gray', $r->due_on->isPast() => 'danger', default => 'warning'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void'])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'overdue' => $q->where('status', 'open')->where('due_on', '<', now()->toDateString()),
                        null, '' => $q, default => $q->where('status', $data['value'])
                    }),
                SelectFilter::make('customer_id')->label('Customer')->relationship('customer', 'name')->searchable(),
                SelectFilter::make('license_id')->label('Licence')->options(fn () => [])->query(fn (Builder $q, array $data) => ($data['value'] ?? null) ? $q->where('license_id', $data['value']) : $q),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/'), 'create' => CreateInvoice::route('/create'), 'view' => ViewInvoice::route('/{record}')];
    }
}
