<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\RoleCatalog;
use App\Security\ScopeType;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
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
                    ->state(fn (RoleAssignment $r) => collect($r->limits ?? [])->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ') ?: '—'),
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
                            ->options(fn () => Role::whereNotIn('key', RoleCatalog::hiddenKeys())->orderBy('security_level')->orderBy('name')->get()
                                ->mapWithKeys(fn (Role $r) => [$r->id => "{$r->name} (L{$r->security_level})"])),
                        Select::make('scope_type')->label('Applies to')->required()->live()
                            ->options(collect(ScopeType::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                        Select::make('scope_id')->label('Which one')->required(fn (Get $get) => $get('scope_type') && $get('scope_type') !== 'tenant')
                            ->visible(fn (Get $get) => $get('scope_type') && $get('scope_type') !== 'tenant')
                            ->options(fn (Get $get) => match ($get('scope_type')) {
                                'legal_entity' => LegalEntity::pluck('name', 'id'),
                                'industry' => Industry::licensed()->orderBy('sort')->pluck('name', 'id'),
                                'branch' => Branch::orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"]),
                                'warehouse' => Warehouse::orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"]),
                                'till' => Till::with('branch')->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->branch->code}-{$t->code} · {$t->name}"]),
                                default => [],
                            })->searchable(),
                        KeyValue::make('limits')->label('Limits (optional)')->keyLabel('Limit')->valueLabel('Whole number')
                            ->helperText('e.g. discount_pct = 10, refund_minor = 50000 (pesewas). Leave empty to use the role defaults.'),
                        DateTimePicker::make('expires_at')->label('Temporary until (optional)')->minDate(now()),
                        TextInput::make('reason')->label('Reason')->maxLength(255)
                            ->required(fn (Get $get) => filled($get('expires_at')))
                            ->helperText('Required for temporary access.'),
                    ])
                    ->action(function (array $data) {
                        $type = ScopeType::from($data['scope_type']);
                        $limits = collect($data['limits'] ?? [])->map(fn ($v) => (int) $v)->all();
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
}
