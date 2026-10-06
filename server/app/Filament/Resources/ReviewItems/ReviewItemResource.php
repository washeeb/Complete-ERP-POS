<?php

namespace App\Filament\Resources\ReviewItems;

use App\Domain\Sync\ReviewQueue;
use App\Filament\Resources\ReviewItems\Pages\ListReviewItems;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\Erp;
use App\Models\ReviewItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** The human conflict queue: offline and sync situations the system must not decide alone. */
class ReviewItemResource extends Resource
{
    protected static ?string $model = ReviewItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Review queue';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('sync.review');
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
        $ids = Erp::branchIds('sync.review');
        $n = ReviewItem::where('status', 'open')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('sync.review');

        return parent::getEloquentQuery()->with('branch')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->sortable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn ($state) => ReviewItem::TYPES[$state] ?? $state)
                    ->color(fn ($state) => in_array($state, ['negative_stock', 'offline_price'], true) ? 'warning' : 'danger'),
                TextColumn::make('branch.code')->label('Branch')->placeholder('—'),
                TextColumn::make('summary')->wrap(),
                TextColumn::make('status')->badge()->color(fn ($state) => $state === 'open' ? 'warning' : 'success'),
                TextColumn::make('resolution')->label('Resolution')->wrap()->limit(80)->placeholder('—')
                    ->description(fn (ReviewItem $r) => $r->resolved_by_name ? "{$r->resolved_by_name}, {$r->resolved_at?->format('j M Y H:i')}" : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'resolved' => 'Resolved'])->default('open'),
                SelectFilter::make('type')->options(ReviewItem::TYPES),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('openSale')->label('Sale')->icon(Heroicon::OutlinedReceiptPercent)
                    ->visible(fn (ReviewItem $r) => $r->subject_type === 'sale' && $r->subject_id)
                    ->url(fn (ReviewItem $r) => SaleResource::getUrl('view', ['record' => $r->subject_id])),
                Action::make('resolve')->label('Resolve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (ReviewItem $r) => $r->status === 'open')
                    ->modalDescription(fn (ReviewItem $r) => $r->summary)
                    ->schema([Textarea::make('resolution')->label('What did you check or do?')->required()->maxLength(255)
                        ->placeholder('e.g. Price change was announced late; accepted. / Counted 3 left; adjustment posted.')])
                    ->action(fn (ReviewItem $record, array $data) => Erp::run(fn ($actor) => app(ReviewQueue::class)
                        ->resolve($actor, $record, $data['resolution']), 'Resolved')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReviewItems::route('/')];
    }
}
