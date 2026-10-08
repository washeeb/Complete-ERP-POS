<?php

namespace App\Filament\Resources\AiAlerts;

use App\Domain\Analytics\InsightAlerts;
use App\Filament\Resources\AiAlerts\Pages\ListAiAlerts;
use App\Filament\Support\Erp;
use App\Models\AiAlert;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Alerts from the nightly insights: what needs looking at today. */
class AiAlertResource extends Resource
{
    protected static ?string $model = AiAlert::class;

    protected static ?string $modelLabel = 'alert';

    protected static ?string $slug = 'alerts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'Alerts';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('insights.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = self::getEloquentQuery()->where('status', 'open')->whereIn('severity', ['high', 'warning'])->count();

        return $n ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('insights.view');

        return parent::getEloquentQuery()
            ->when(! Erp::canAnywhere('insights.loss_prevention'), fn ($q) => $q->where('kind', '<>', 'loss'))
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids)); // company-wide alerts (margins, cash, staff) need a company-wide grant
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('severity')->badge()->color(fn ($state) => ['high' => 'danger', 'warning' => 'warning'][$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => ['high' => 'High', 'warning' => 'Warning', 'info' => 'Info'][$state] ?? $state),
                TextColumn::make('kind')->label('Type')->formatStateUsing(fn ($state) => InsightAlerts::KINDS[$state] ?? $state),
                TextColumn::make('title')->wrap()->searchable()->description(fn (AiAlert $r) => $r->detail),
                TextColumn::make('updated_at')->label('Last seen')->since(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => AiAlert::STATUSES[$state] ?? $state)
                    ->description(fn (AiAlert $r) => $r->handled_by_name ? trim("{$r->handled_by_name} {$r->handled_note}") : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(AiAlert::STATUSES)->default('open'),
                SelectFilter::make('kind')->label('Type')->options(fn () => Erp::canAnywhere('insights.loss_prevention') ? InsightAlerts::KINDS : array_diff_key(InsightAlerts::KINDS, ['loss' => 1])),
                SelectFilter::make('severity')->options(['high' => 'High', 'warning' => 'Warning', 'info' => 'Info']),
            ])
            ->recordActions([
                Action::make('resolve')->label('Dealt with')->icon(Heroicon::OutlinedCheck)->color('success')->visible(fn (AiAlert $r) => $r->status === 'open')
                    ->schema([TextInput::make('note')->label('What was done')->maxLength(255)])
                    ->action(fn (AiAlert $record, array $data) => Erp::run(fn ($actor) => app(InsightAlerts::class)->handle($actor, $record, 'resolved', $data['note'] ?? null), 'Marked as dealt with')),
                Action::make('dismiss')->label('Not a problem')->icon(Heroicon::OutlinedXMark)->color('gray')->visible(fn (AiAlert $r) => $r->status === 'open')
                    ->schema([TextInput::make('note')->label('Why (it stays quiet for 30 days)')->maxLength(255)])
                    ->action(fn (AiAlert $record, array $data) => Erp::run(fn ($actor) => app(InsightAlerts::class)->handle($actor, $record, 'dismissed', $data['note'] ?? null), 'Dismissed')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAiAlerts::route('/')];
    }
}
