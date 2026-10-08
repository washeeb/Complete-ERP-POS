<?php

namespace App\Filament\Resources\AiRequests;

use App\Domain\Ai\AiGateway;
use App\Filament\Resources\AiRequests\Pages\ListAiRequests;
use App\Filament\Support\Erp;
use App\Models\AiRequest;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Every call to an AI model: who, what was sent (masked), the answer, tokens and cost. Read-only. */
class AiRequestResource extends Resource
{
    protected static ?string $model = AiRequest::class;

    protected static ?string $modelLabel = 'AI request';

    protected static ?string $slug = 'ai-usage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'AI usage log';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('ai.usage.view');
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

    public static function usd(int $micro): string
    {
        return 'US$ '.number_format($micro / 1_000_000, 4);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            TextEntry::make('created_at')->label('When')->dateTime('j M Y H:i:s'),
            TextEntry::make('user_name')->label('Who'),
            TextEntry::make('feature')->formatStateUsing(fn ($state) => AiGateway::FEATURES[$state] ?? $state),
            TextEntry::make('model')->formatStateUsing(fn ($state, AiRequest $r) => "{$r->provider} · {$state}"),
            TextEntry::make('tokens_in')->label('Tokens in / out')->formatStateUsing(fn ($state, AiRequest $r) => "{$r->tokens_in} / {$r->tokens_out}"),
            TextEntry::make('cost_micro_usd')->label('Cost')->formatStateUsing(fn ($state) => self::usd((int) $state)),
            TextEntry::make('status')->badge()->color(fn ($state) => $state === 'ok' ? 'success' : 'danger')->belowContent(fn (AiRequest $r) => $r->error),
            TextEntry::make('duration_ms')->label('Took')->formatStateUsing(fn ($state) => number_format($state / 1000, 1).' s'),
            TextEntry::make('prompt')->label('Sent (as the model saw it)')->columnSpanFull()->extraAttributes(['style' => 'white-space:pre-wrap;font-size:.8rem']),
            TextEntry::make('response')->label('Answer')->placeholder('—')->columnSpanFull()->extraAttributes(['style' => 'white-space:pre-wrap']),
            TextEntry::make('tools')->label('Reports used')->columnSpanFull()->formatStateUsing(fn ($state, AiRequest $r) => collect($r->tools)->map(fn ($t) => $t['name'].' '.json_encode($t['arguments']))->implode("\n") ?: '—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M H:i')->sortable(),
                TextColumn::make('user_name')->label('Who')->searchable(),
                TextColumn::make('feature')->formatStateUsing(fn ($state) => AiGateway::FEATURES[$state] ?? $state),
                TextColumn::make('provider')->formatStateUsing(fn ($state, AiRequest $r) => "{$state} · {$r->model}"),
                TextColumn::make('prompt')->label('Question')->limit(60)->searchable(),
                TextColumn::make('cost_micro_usd')->label('Cost')->formatStateUsing(fn ($state) => self::usd((int) $state)),
                TextColumn::make('status')->badge()->color(fn ($state) => $state === 'ok' ? 'success' : 'danger'),
            ])
            ->recordActions([ViewAction::make()])
            ->filters([SelectFilter::make('feature')->options(AiGateway::FEATURES), SelectFilter::make('status')->options(['ok' => 'OK', 'error' => 'Error'])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAiRequests::route('/')];
    }
}
