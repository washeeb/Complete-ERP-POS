<?php

namespace App\Filament\Vendor\Resources\Customers;

use App\Filament\Vendor\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Vendor\Resources\Customers\Pages\EditCustomer;
use App\Filament\Vendor\Resources\Customers\Pages\ListCustomers;
use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorLicense;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** SyncLytics' customers: who licences are issued to, invoiced and reminded. */
class CustomerResource extends Resource
{
    protected static ?string $model = VendorCustomer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Licences';

    protected static ?string $modelLabel = 'customer';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components(self::fields());
    }

    /** @return list<Component> also used to add a customer while issuing a licence */
    public static function fields(): array
    {
        return [
            TextInput::make('code')->required()->maxLength(20)->unique(ignoreRecord: true)->helperText('Short code, e.g. OHENEBA'),
            TextInput::make('name')->label('Trading name')->required()->maxLength(255),
            TextInput::make('legal_name')->label('Legal name (on the licence and invoices)')->maxLength(255),
            TextInput::make('industry')->maxLength(60)->placeholder('Pharmacy, retail…'),
            TextInput::make('contact_name')->label('Contact person')->maxLength(120),
            TextInput::make('email')->email()->maxLength(255)->helperText('Renewal reminders and keys'),
            TextInput::make('phone')->tel()->maxLength(30),
            TextInput::make('whatsapp')->label('WhatsApp')->tel()->maxLength(30)->helperText('Renewal reminders'),
            TextInput::make('tin')->label('TIN')->maxLength(40),
            TextInput::make('address')->maxLength(255),
            Toggle::make('is_active')->label('Active')->default(true)->inline(false),
            Textarea::make('notes')->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (VendorCustomer $r) => $r->code),
                TextColumn::make('contact_name')->label('Contact')->description(fn (VendorCustomer $r) => $r->phone)->toggleable(),
                TextColumn::make('licenses_count')->counts('licenses')->label('Licences'),
                TextColumn::make('current')->label('Current licence')->state(function (VendorCustomer $r) {
                    $l = $r->licenses()->whereNull('revoked_at')->whereNull('superseded_by')->orderByDesc('expires_on')->first();

                    return $l ? 'until '.$l->expires_on?->format('j M Y').' · '.VendorLicense::STATUSES[$l->status()] : '—';
                }),
                TextColumn::make('owed')->label('Owed')->state(fn (VendorCustomer $r) => VendorUi::money((int) $r->invoices()->where('status', 'open')->get()->sum(fn ($i) => $i->balanceMinor()))),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Active')])
            ->recordActions([
                EditAction::make(),
                Action::make('licences')->label('Licences')->color('gray')->url(fn (VendorCustomer $r) => LicenseResource::getUrl('index', ['filters' => ['customer_id' => ['value' => $r->id]]])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomers::route('/'), 'create' => CreateCustomer::route('/create'), 'edit' => EditCustomer::route('/{record}/edit')];
    }
}
