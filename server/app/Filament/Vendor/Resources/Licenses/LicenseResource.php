<?php

namespace App\Filament\Vendor\Resources\Licenses;

use App\Domain\Licensing\Vendor\VendorLicensing;
use App\Filament\Vendor\Resources\Customers\CustomerResource;
use App\Filament\Vendor\Resources\Licenses\Pages\IssueLicense;
use App\Filament\Vendor\Resources\Licenses\Pages\ListLicenses;
use App\Filament\Vendor\Resources\Licenses\Pages\ViewLicense;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorAddon;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorLicense;
use App\Models\Vendor\VendorPlan;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Every licence issued: to whom, for which installation, package, dates, price, status and how the installation is doing. */
class LicenseResource extends Resource
{
    protected static ?string $model = VendorLicense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Licences';

    protected static ?string $modelLabel = 'licence';

    protected static ?int $navigationSort = 1;

    public static function canEdit(Model $record): bool
    {
        return false; // a signed licence never changes: renew, change the package (a new key) or revoke
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $pkg = function (Get $get) {
            $p = app(VendorLicensing::class)->package(VendorPlan::find($get('plan_id')), array_values($get('addons') ?? []));
            $limit = fn ($v) => $v === null ? 'unlimited' : $v;

            return "Branches {$limit($p['branches'])} · tills and devices {$limit($p['devices'])} · users {$limit($p['users'])} · modules "
                .($p['modules'] === null ? 'all' : (implode(', ', $p['modules']) ?: 'none')).' · features '.($p['features'] === null ? 'all' : (implode(', ', $p['features']) ?: 'none'))
                .' · '.VendorUi::money($p['price_minor'], $p['currency']).' for '.$p['months'].' months';
        };

        return $schema->columns(1)->components([
            Section::make('Who and where')->columns(2)->schema([
                Select::make('customer_id')->label('Customer')->required()->searchable()->preload()
                    ->options(fn () => VendorCustomer::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->createOptionForm(CustomerResource::fields())->createOptionUsing(fn (array $data) => VendorCustomer::create($data)->id),
                Select::make('kind')->options(VendorLicense::KINDS)->default('paid')->required()->live()
                    ->helperText('Trial: 30 days unless you give an end date; no charge.'),
                Textarea::make('request_code')->label('Installation code (SYNREQ-…)')->rows(3)->columnSpanFull()
                    ->required(fn (Get $get) => ! $get('replaces'))->hidden(fn (Get $get) => (bool) $get('replaces'))
                    ->helperText('From the customer\'s Settings → Licence page. It ties the key to that server.'),
                TextInput::make('site')->label('Site / server')->maxLength(120)->placeholder('e.g. Cloud server, Osu branch PC'),
                TextInput::make('replaces')->hidden()->dehydrated(),
            ]),
            Section::make('Package')->columns(2)->schema([
                Select::make('plan_id')->label('Plan')->options(fn () => VendorPlan::where('is_active', true)->orderBy('price_minor')->pluck('name', 'id'))->live()
                    ->placeholder('No plan (type the limits below)'),
                Repeater::make('addons')->label('Add-ons')->columns(2)->defaultItems(0)->addActionLabel('Add an add-on')->live()->schema([
                    Select::make('addon_id')->label('Add-on')->required()->options(fn () => VendorAddon::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                    TextInput::make('qty')->numeric()->minValue(1)->default(1)->required(),
                ]),
                Text::make(fn (Get $get) => new HtmlString('<strong>Package:</strong> '.e($pkg($get))))->columnSpanFull(),
                Toggle::make('customise')->label('Change the limits, modules, features or price for this licence')->live()->columnSpanFull(),
                TextInput::make('branches')->numeric()->minValue(1)->placeholder('unlimited')->visible(fn (Get $get) => $get('customise')),
                TextInput::make('devices')->label('Tills and devices')->numeric()->minValue(1)->placeholder('unlimited')->visible(fn (Get $get) => $get('customise')),
                TextInput::make('users')->label('Active users')->numeric()->minValue(1)->placeholder('unlimited')->visible(fn (Get $get) => $get('customise')),
                TextInput::make('price')->label('Price for the period')->numeric()->minValue(0)->visible(fn (Get $get) => $get('customise')),
                Toggle::make('all_modules')->label('Every industry module')->default(true)->live()->visible(fn (Get $get) => $get('customise')),
                CheckboxList::make('modules')->options(fn () => VendorLicensing::modules())->columns(3)->visible(fn (Get $get) => $get('customise') && ! $get('all_modules')),
                Toggle::make('all_features')->label('Every feature (finance, HR)')->default(true)->live()->visible(fn (Get $get) => $get('customise')),
                CheckboxList::make('features')->options(VendorLicensing::FEATURES)->visible(fn (Get $get) => $get('customise') && ! $get('all_features')),
            ]),
            Section::make('Dates and rules')->columns(4)->schema([
                DatePicker::make('starts_on')->label('Starts')->default(now())->required(),
                DatePicker::make('expires_on')->label('Ends')->helperText('Empty: the plan\'s length (trial: 30 days)'),
                TextInput::make('grace_days')->label('Grace after the end (days)')->numeric()->minValue(0)->maxValue(90)->default(14),
                TextInput::make('checkin_days')->label('Must check in every (days)')->numeric()->minValue(0)->maxValue(365)->default(30)
                    ->helperText('0 = never (offline sites)'),
            ]),
            Section::make('Invoice and notes')->columns(2)->schema([
                Toggle::make('invoice')->label('Create the invoice')->default(true)->visible(fn (Get $get) => $get('kind') === 'paid'),
                TextInput::make('due_days')->label('Pay within (days)')->numeric()->minValue(0)->default(14)->visible(fn (Get $get) => $get('kind') === 'paid'),
                Textarea::make('notes')->columnSpanFull(),
                VendorUi::confirmPassword(),
            ]),
        ]);
    }

    /** Form → VendorLicensing::issue() data (limits etc. only when customised, so the plan decides otherwise). */
    public static function issueData(array $d): array
    {
        $out = ['customer_id' => $d['customer_id'], 'request_code' => $d['request_code'] ?? null, 'replaces' => $d['replaces'] ?? null, 'kind' => $d['kind'],
            'site' => $d['site'] ?? null, 'plan_id' => $d['plan_id'] ?? null, 'addons' => array_values($d['addons'] ?? []), 'starts_on' => $d['starts_on'],
            'expires_on' => $d['expires_on'] ?? null, 'grace_days' => (int) ($d['grace_days'] ?? 14), 'checkin_days' => (int) ($d['checkin_days'] ?? 30),
            'notes' => $d['notes'] ?? null, 'invoice' => (bool) ($d['invoice'] ?? false), 'due_days' => (int) ($d['due_days'] ?? 14)];
        if (! empty($d['customise'])) {
            foreach (['branches', 'devices', 'users'] as $k) {
                $out[$k] = ($d[$k] ?? '') === '' ? null : (int) $d[$k];
            }
            $out['modules'] = VendorUi::listOrAll((bool) ($d['all_modules'] ?? true), $d['modules'] ?? []);
            $out['features'] = VendorUi::listOrAll((bool) ($d['all_features'] ?? true), $d['features'] ?? []);
            if (($d['price'] ?? '') !== '') {
                $out['price_minor'] = VendorUi::toMinor($d['price']);
            }
        }

        return $out;
    }

    public static function infolist(Schema $schema): Schema
    {
        $limit = fn (VendorLicense $r, string $k) => ($v = $r->payload['limits'][$k] ?? null) === null ? 'unlimited' : (string) $v;

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('short')->label('Licence')->state(fn (VendorLicense $r) => $r->short())->copyable(),
                TextEntry::make('status')->badge()->state(fn (VendorLicense $r) => VendorLicense::STATUSES[$r->status()])
                    ->color(fn (VendorLicense $r) => ['active' => 'success', 'expiring' => 'warning', 'grace' => 'danger', 'expired' => 'danger', 'revoked' => 'gray', 'replaced' => 'gray'][$r->status()]),
                TextEntry::make('client.name')->label('Customer')->url(fn (VendorLicense $r) => $r->customer_id ? CustomerResource::getUrl('edit', ['record' => $r->customer_id]) : null),
                TextEntry::make('kind')->formatStateUsing(fn ($state) => VendorLicense::KINDS[$state] ?? $state),
                TextEntry::make('starts_on')->label('Starts')->date('j M Y'),
                TextEntry::make('expires_on')->label('Ends')->date('j M Y')->belowContent(fn (VendorLicense $r) => $r->daysLeft() === null ? null
                    : ($r->daysLeft() >= 0 ? "{$r->daysLeft()} days left" : abs($r->daysLeft()).' days ago')),
                TextEntry::make('plan.name')->label('Plan')->placeholder('custom'),
                TextEntry::make('price_minor')->label('Price')->formatStateUsing(fn ($state, VendorLicense $r) => VendorUi::money((int) $state, $r->currency)),
                TextEntry::make('limits')->label('Branches / devices / users')->state(fn (VendorLicense $r) => $limit($r, 'branches').' / '.$limit($r, 'devices').' / '.$limit($r, 'users')),
                TextEntry::make('modules')->label('Industry modules')->state(fn (VendorLicense $r) => ($m = $r->payload['modules'] ?? null) === null ? 'all' : (implode(', ', $m) ?: 'none')),
                TextEntry::make('features')->label('Features')->state(fn (VendorLicense $r) => ($f = $r->payload['features'] ?? null) === null ? 'all' : (implode(', ', $f) ?: 'none')),
                TextEntry::make('addons')->label('Add-ons')->state(fn (VendorLicense $r) => collect($r->addons ?? [])->map(fn ($a) => "{$a['qty']} × {$a['name']}")->implode(', ') ?: '—'),
                TextEntry::make('site')->placeholder('—'),
                TextEntry::make('installation_id')->label('Installation')->copyable(),
                TextEntry::make('issued_by')->label('Issued by')->belowContent(fn (VendorLicense $r) => $r->created_at?->format('j M Y H:i')),
                TextEntry::make('rules')->label('Grace / check-in')->state(fn (VendorLicense $r) => ($r->payload['grace_days'] ?? 14).' days / '
                    .(($c = $r->payload['checkin_days'] ?? 30) ? "every {$c} days" : 'never')),
                TextEntry::make('replaces')->label('Replaces')->placeholder('—')->formatStateUsing(fn ($state) => strtoupper(substr(str_replace('-', '', $state), 0, 8)))
                    ->url(fn (VendorLicense $r) => $r->replaces ? self::getUrl('view', ['record' => $r->replaces]) : null),
                TextEntry::make('superseded_by')->label('Replaced by')->placeholder('—')->formatStateUsing(fn ($state) => strtoupper(substr(str_replace('-', '', $state), 0, 8)))
                    ->url(fn (VendorLicense $r) => $r->superseded_by ? self::getUrl('view', ['record' => $r->superseded_by]) : null),
                TextEntry::make('revoked_reason')->label('Revoked')->visible(fn (VendorLicense $r) => (bool) $r->revoked_at)
                    ->belowContent(fn (VendorLicense $r) => $r->revoked_at?->format('j M Y').' by '.$r->revoked_by),
                TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Installation health (from the daily check-ins)')->columns(4)->schema([
                TextEntry::make('seen')->label('Last seen')->state(fn (VendorLicense $r) => ($h = VendorLicensing::health($r))['last']
                    ? Carbon::parse($h['last']->created_at)->diffForHumans().' from '.($h['last']->ip ?? '?') : 'never'),
                TextEntry::make('version')->label('Version / build')->state(fn (VendorLicense $r) => (($h = VendorLicensing::health($r))['version'] ?? '—').' / '.($h['last']->build_id ?? '—')),
                TextEntry::make('use')->label('In use (branches / devices / users)')->state(fn (VendorLicense $r) => ($c = VendorLicensing::health($r)['counts'])
                    ? ($c['branches'] ?? '?').' / '.($c['devices'] ?? '?').' / '.($c['users'] ?? '?') : '—')
                    ->color(fn (VendorLicense $r) => VendorLicensing::health($r)['over'] ? 'danger' : null)
                    ->belowContent(fn (VendorLicense $r) => ($o = VendorLicensing::health($r)['over']) ? 'Over the licence: '.implode(', ', $o) : null),
                TextEntry::make('alarm')->label('Warnings')->state(function (VendorLicense $r) {
                    $h = VendorLicensing::health($r);
                    $w = array_filter([$h['tampered'] ? 'modified software' : null, $h['machines'] > 1 ? "{$h['machines']} machines in 30 days" : null,
                        $h['days_silent'] !== null && $h['days_silent'] > 7 ? "silent {$h['days_silent']} days" : null]);

                    return $w ? implode(' · ', $w) : 'none';
                })->color(fn ($state) => $state === 'none' ? 'success' : 'danger'),
            ]),
            Section::make('Licence key')->collapsed()->schema([
                TextEntry::make('license_key')->hiddenLabel()->copyable()->fontFamily('mono')->size('xs')
                    ->helperText('The customer pastes it on Settings → Licence → Enter licence key. A renewal reaches them by itself at the next check-in.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('client.name')->label('Customer')->searchable()->description(fn (VendorLicense $r) => $r->short().($r->site ? " · {$r->site}" : '')),
                TextColumn::make('plan.name')->label('Plan')->placeholder('custom')->description(fn (VendorLicense $r) => VendorLicense::KINDS[$r->kind] ?? $r->kind),
                TextColumn::make('expires_on')->label('Ends')->date('j M Y')->sortable()
                    ->description(fn (VendorLicense $r) => $r->daysLeft() === null ? null : ($r->daysLeft() >= 0 ? "{$r->daysLeft()} days left" : abs($r->daysLeft()).' days ago')),
                TextColumn::make('status')->badge()->state(fn (VendorLicense $r) => VendorLicense::STATUSES[$r->status()])
                    ->color(fn (VendorLicense $r) => ['active' => 'success', 'expiring' => 'warning', 'grace' => 'danger', 'expired' => 'danger', 'revoked' => 'gray', 'replaced' => 'gray'][$r->status()]),
                TextColumn::make('price_minor')->label('Price')->formatStateUsing(fn ($state, VendorLicense $r) => VendorUi::money((int) $state, $r->currency))->toggleable(),
                TextColumn::make('last_checkin_at')->label('Last seen')->since()->placeholder('never')->sortable(),
                TextColumn::make('created_at')->label('Issued')->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')->label('Status')->options(['current' => 'Current (not replaced or revoked)', 'expiring' => 'Ending within 60 days',
                    'expired' => 'Ended', 'revoked' => 'Revoked', 'replaced' => 'Replaced'])->default('current')
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'current' => $q->whereNull('revoked_at')->whereNull('superseded_by'),
                        'expiring' => $q->whereNull('revoked_at')->whereNull('superseded_by')->whereBetween('expires_on', [now()->toDateString(), now()->addDays(60)->toDateString()]),
                        'expired' => $q->whereNull('revoked_at')->whereNull('superseded_by')->where('expires_on', '<', now()->toDateString()),
                        'revoked' => $q->whereNotNull('revoked_at'),
                        'replaced' => $q->whereNotNull('superseded_by'),
                        default => $q,
                    }),
                SelectFilter::make('customer_id')->label('Customer')->relationship('client', 'name')->searchable()->preload(),
                SelectFilter::make('plan_id')->label('Plan')->relationship('plan', 'name'),
                SelectFilter::make('kind')->options(VendorLicense::KINDS),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLicenses::route('/'), 'create' => IssueLicense::route('/issue'), 'view' => ViewLicense::route('/{record}')];
    }
}
