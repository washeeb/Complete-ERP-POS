<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Domain\Purchasing\SupplierOnboarding;
use App\Filament\Support\Erp;
use App\Models\SupplierDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** The supplier's documents on file: certificates and licences, with expiry, who added and who checked each one. */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Erp::canAnywhere('purchasing.view') || Erp::canAnywhere('suppliers.manage');
    }

    public function table(Table $table): Table
    {
        $required = app(SupplierOnboarding::class)->required(Erp::user()->tenant_id);

        return $table
            ->description('Required by your company: '.(collect($required)->map(fn ($k) => SupplierDocument::KINDS[$k])->implode(', ') ?: 'none').' (Settings → Purchasing).')
            ->columns([
                TextColumn::make('kind')->label('Document')->formatStateUsing(fn ($state) => SupplierDocument::KINDS[$state] ?? $state)
                    ->description(fn (SupplierDocument $d) => in_array($d->kind, $required, true) ? 'required' : null),
                TextColumn::make('number')->placeholder('—'),
                TextColumn::make('expires_on')->label('Expires')->date()->placeholder('does not expire')
                    ->color(fn (SupplierDocument $d) => $d->expired() ? 'danger' : ($d->expires_on && $d->expires_on->lte(now()->addDays(30)) ? 'warning' : null))
                    ->description(fn (SupplierDocument $d) => $d->expired() ? 'expired' : null),
                TextColumn::make('uploaded_by_name')->label('Added by'),
                TextColumn::make('verified_by_name')->label('Checked by')->placeholder('not yet'),
            ])
            ->headerActions([
                Action::make('add')->label('Add a document')->icon(Heroicon::OutlinedPaperClip)->visible(fn () => Erp::canAnywhere('suppliers.manage'))
                    ->schema([
                        Select::make('kind')->label('Document')->options(SupplierDocument::KINDS)->required(),
                        TextInput::make('number')->label('Number on it')->maxLength(80),
                        DatePicker::make('issued_on')->label('Issued'),
                        DatePicker::make('expires_on')->label('Expires')->helperText('You are told before it expires, and orders stop when a required one has expired.'),
                        FileUpload::make('file')->label('Scan or photo (PDF or picture)')->disk('local')->directory('supplier-documents')->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)->storeFileNamesIn('file_name'),
                    ])
                    ->action(fn (array $data) => Erp::run(fn ($actor) => app(SupplierOnboarding::class)->addDocument($actor, $this->getOwnerRecord(), $data,
                        $data['file'] ?? null, $data['file_name'] ?? null), 'Document added')),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')->visible(fn (SupplierDocument $d) => (bool) $d->file_path)
                    ->action(fn (SupplierDocument $d) => Storage::disk('local')->download($d->file_path, $d->file_name ?: basename($d->file_path))),
                Action::make('verify')->label('Checked against the original')->icon(Heroicon::OutlinedCheckBadge)
                    ->visible(fn (SupplierDocument $d) => ! $d->verified_at && Erp::canAnywhere('suppliers.approve'))->requiresConfirmation()
                    ->modalDescription('Confirm you have seen the original (or checked it with the issuer) and it matches.')
                    ->action(fn (SupplierDocument $d) => Erp::run(fn ($actor) => app(SupplierOnboarding::class)->verifyDocument($actor, $d), 'Checked')),
                Action::make('remove')->label('Remove')->icon(Heroicon::OutlinedTrash)->color('danger')->visible(fn () => Erp::canAnywhere('suppliers.manage'))
                    ->schema([TextInput::make('reason')->label('Why (kept in the audit log)')->required()->maxLength(255)])
                    ->action(fn (SupplierDocument $d, array $data) => Erp::run(fn ($actor) => app(SupplierOnboarding::class)->removeDocument($actor, $d, $data['reason']) ?? true, 'Removed')),
            ])
            ->defaultSort('kind');
    }
}
