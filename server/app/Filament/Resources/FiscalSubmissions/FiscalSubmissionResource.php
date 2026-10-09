<?php

namespace App\Filament\Resources\FiscalSubmissions;

use App\Domain\Fiscal\FiscalService;
use App\Filament\Resources\FiscalSubmissions\Pages\ListFiscalSubmissions;
use App\Filament\Support\Erp;
use App\Models\FiscalSubmission;
use BackedEnum;
use Filament\Actions\Action;
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

/** What was reported to GRA (E-VAT), GRA's answer, and what is still waiting or was refused. */
class FiscalSubmissionResource extends Resource
{
    protected static ?string $model = FiscalSubmission::class;

    protected static ?string $modelLabel = 'GRA E-VAT report';

    protected static ?string $slug = 'gra-evat';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'GRA E-VAT';

    protected static ?int $navigationSort = 91;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('finance.view');
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('doc_number')->label('Document'), TextEntry::make('doc_type')->label('Type'), TextEntry::make('doc_date')->date('j M Y'),
            TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => FiscalSubmission::STATUSES[$state] ?? $state),
            TextEntry::make('gra_reference')->label('GRA reference')->placeholder('—'), TextEntry::make('attempts'),
            TextEntry::make('error')->placeholder('—')->columnSpanFull(),
            TextEntry::make('payload')->label('Sent')->columnSpanFull()->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->extraAttributes(['style' => 'white-space:pre-wrap;font-family:monospace;font-size:.75rem']),
            TextEntry::make('response')->label('GRA answered')->columnSpanFull()->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->extraAttributes(['style' => 'white-space:pre-wrap;font-family:monospace;font-size:.75rem']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('doc_date')->label('Date')->date('j M Y')->sortable(),
                TextColumn::make('doc_number')->label('Document')->searchable(),
                TextColumn::make('doc_type')->label('Type')->badge(),
                TextColumn::make('mode'),
                TextColumn::make('gra_reference')->label('GRA reference')->placeholder('—')->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => FiscalSubmission::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['submitted' => 'success', 'rejected' => 'danger', 'pending' => 'warning'][$state] ?? 'gray')->description(fn (FiscalSubmission $r) => $r->error),
            ])
            ->filters([SelectFilter::make('status')->options(FiscalSubmission::STATUSES), SelectFilter::make('doc_type')->label('Type')->options(['sale' => 'Till sale', 'invoice' => 'Invoice', 'credit_note' => 'Credit note'])])
            ->recordActions([
                ViewAction::make(),
                Action::make('retry')->label('Send again')->icon(Heroicon::OutlinedArrowPath)->visible(fn (FiscalSubmission $r) => $r->status !== 'submitted' && Erp::canAnywhere('finance.journal'))
                    ->action(fn (FiscalSubmission $record) => app(FiscalService::class)->submit($record->forceFill(['status' => 'pending']))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFiscalSubmissions::route('/')];
    }
}
