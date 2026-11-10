<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\RoleCatalog;
use App\Security\ScopeType;
use App\Support\Plain;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AccessGrantsRelationManager extends RelationManager
{
    protected static string $relationship = 'roleAssignments';

    protected static ?string $title = 'Access (roles and where they apply)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Erp::can('admin.users.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('role.name')->label('Role'),
                TextColumn::make('scope')->label('Applies to')->state(fn (RoleAssignment $r) => $r->scopeLabel()),
                TextColumn::make('limits')->label('Limits')
                    ->state(fn (RoleAssignment $r) => Plain::limits($r->limits, self::currency()))->wrap(),
                TextColumn::make('status')->badge()->state(fn (RoleAssignment $r) => match (true) {
                    $r->revoked_at !== null => 'Revoked',
                    $r->expires_at?->isPast() => 'Expired',
                    $r->expires_at !== null => 'Temporary',
                    default => 'Active',
                })->color(fn (string $state) => match ($state) {
                    'Active' => 'success', 'Temporary' => 'warning', default => 'gray',
                }),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('—'),
                TextColumn::make('granted_by_name')->label('Granted by'),
                TextColumn::make('reason')->limit(40)->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('current')->label('Current only')->default(true)
                    ->queries(true: fn ($q) => $q->active(), false: fn ($q) => $q, blank: fn ($q) => $q),
            ])
            ->defaultSort('created_at', 'desc')
            ->pushHeaderActions([
                Action::make('grant')->label('Grant access')->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => Erp::can('admin.roles.manage'))
                    ->schema([
                        Select::make('role_id')->label('Role')->required()
                            ->options(fn () => Role::whereNull('archived_at')->whereNotIn('key', RoleCatalog::hiddenKeys())->orderBy('security_level')->orderBy('name')->get()
                                ->mapWithKeys(fn (Role $r) => [$r->id => "{$r->name} (L{$r->security_level})"])),
                        Select::make('scope_type')->label('Applies to')->required()->live()
                            ->options(collect(ScopeType::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                        Select::make('scope_id')->label('Which one')->required(fn (Get $get) => $get('scope_type') && $get('scope_type') !== 'tenant')
                            ->visible(fn (Get $get) => $get('scope_type') && $get('scope_type') !== 'tenant')
                            ->options(fn (Get $get, $state) => match ($get('scope_type')) {
                                'legal_entity' => LegalEntity::choices($state),
                                'industry' => Industry::licensed()->orderBy('sort')->pluck('name', 'id'),
                                'branch' => Branch::choices($state, 'both'),
                                'warehouse' => Warehouse::orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"]),
                                'till' => Till::with('branch')->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->branch->code}-{$t->code} · {$t->name}"]),
                                default => [],
                            })->searchable(),
                        // In cedis and percent, not pesewas and key names (owner 2026-11-09). Empty: the role's usual limit.
                        Fieldset::make('Limits (optional: empty uses the role\'s usual limits)')->columns(2)->schema([
                            TextInput::make('limit_po')->label('Approves orders up to')->numeric()->minValue(0)->prefix(fn () => self::symbol()),
                            TextInput::make('limit_expense')->label('Approves expenses up to')->numeric()->minValue(0)->prefix(fn () => self::symbol()),
                            TextInput::make('limit_credit')->label('Lets a customer go over their credit by up to')->numeric()->minValue(0)->prefix(fn () => self::symbol()),
                            TextInput::make('limit_discount')->label('Gives discounts up to')->numeric()->minValue(0)->maxValue(100)->suffix('%'),
                        ]),
                        DateTimePicker::make('expires_at')->label('Temporary until (optional)')->minDate(now()),
                        TextInput::make('reason')->label('Reason')->maxLength(255)
                            ->required(fn (Get $get) => filled($get('expires_at')))
                            ->helperText('Required for temporary access.'),
                    ])
                    ->action(function (array $data) {
                        $type = ScopeType::from($data['scope_type']);
                        $limits = array_filter([
                            'po_approve_minor' => filled($data['limit_po'] ?? null) ? (int) round((float) $data['limit_po'] * 100) : null,
                            'expense_approve_minor' => filled($data['limit_expense'] ?? null) ? (int) round((float) $data['limit_expense'] * 100) : null,
                            'credit_override_minor' => filled($data['limit_credit'] ?? null) ? (int) round((float) $data['limit_credit'] * 100) : null,
                            'discount_bp' => filled($data['limit_discount'] ?? null) ? (int) round((float) $data['limit_discount'] * 100) : null,
                        ], fn ($v) => $v !== null);
                        Erp::run(fn ($actor) => app(AccessService::class)->grant(
                            $actor, $this->getOwnerRecord(), Role::findOrFail($data['role_id']), $type,
                            $type === ScopeType::Tenant ? $actor->tenantId : $data['scope_id'], $limits,
                            filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null, $data['reason'] ?? null,
                        ), 'Access granted');
                    }),
            ])
            ->recordActions([
                Action::make('revoke')->label('Revoke')->color('danger')->icon(Heroicon::OutlinedNoSymbol)
                    ->visible(fn (RoleAssignment $r) => $r->revoked_at === null && Erp::can('admin.roles.manage'))
                    ->requiresConfirmation()
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (RoleAssignment $record, array $data) => Erp::run(fn ($actor) => app(AccessService::class)
                        ->revoke($actor, $record, $data['reason']), 'Access revoked')),
            ]);
    }

    private static function currency(): string
    {
        return once(fn () => (string) (Tenant::whereKey(Erp::user()?->tenant_id)->value('currency_code') ?: 'GHS'));
    }

    private static function symbol(): string
    {
        return ['GHS' => 'GH₵', 'NGN' => '₦', 'KES' => 'KSh', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][self::currency()] ?? self::currency();
    }
}
