<?php

namespace App\Filament\Resources\SupportAccessGrants\Pages;

use App\Domain\Remote\RemoteAccess;
use App\Filament\Resources\SupportAccessGrants\SupportAccessGrantResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\SupportAccessGrant;
use App\Models\SupportReport;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSupportAccessGrants extends ListRecords
{
    protected static string $resource = SupportAccessGrantResource::class;

    protected static ?string $title = 'Support access';

    protected ?string $subheading = 'Let SyncLytics support reach your tills and computers to help: for a few hours, or until a problem report is closed. Free of charge. Everyone signed in sees a banner while it is open, and you can withdraw it at any time.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('grant')->label('Give support access')->icon(Heroicon::OutlinedLifebuoy)
                ->visible(fn () => Erp::can('support.access.grant'))
                ->schema([
                    Radio::make('level')->label('Support may')->required()->default('control')->options(SupportAccessGrant::LEVELS),
                    Select::make('branch_id')->label('Devices of')->placeholder('Every branch')->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
                    Select::make('until')->label('For')->required()->default('2')->live()->options([
                        '1' => '1 hour', '2' => '2 hours', '4' => '4 hours', '8' => 'The working day (8 hours)', '24' => '24 hours', 'report' => 'Until a problem report is closed',
                    ]),
                    Select::make('support_report_id')->label('Problem report')->visible(fn ($get) => $get('until') === 'report')->required(fn ($get) => $get('until') === 'report')
                        ->options(fn () => SupportReport::where('status', 'open')->latest()->limit(50)->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->number} · ".mb_strimwidth($r->message, 0, 60, '…')])),
                    TextInput::make('reason')->label('What support is helping with')->required()->maxLength(255)->placeholder('e.g. Printer on Till 2 prints blank receipts'),
                ])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(RemoteAccess::class)->grant($actor, [
                    'level' => $data['level'], 'branch_id' => $data['branch_id'] ?? null, 'reason' => $data['reason'],
                    'hours' => $data['until'] === 'report' ? null : (int) $data['until'], 'support_report_id' => $data['until'] === 'report' ? $data['support_report_id'] : null,
                ]), 'Support access given')),
        ];
    }
}
