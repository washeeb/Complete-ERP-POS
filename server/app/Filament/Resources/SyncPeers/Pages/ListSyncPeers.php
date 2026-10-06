<?php

namespace App\Filament\Resources\SyncPeers\Pages;

use App\Domain\Pos\TillFileImport;
use App\Domain\Sync\NodeSetup;
use App\Domain\Sync\SyncFile;
use App\Filament\Resources\SyncPeers\SyncPeerResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListSyncPeers extends ListRecords
{
    protected static string $resource = SyncPeerResource::class;

    protected static ?string $title = 'Sync Center';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addBranch')->label('Add branch server')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => config('erp.node.role') !== 'branch')
                ->schema([
                    TextInput::make('node_id')->label('Branch server id')->required()->maxLength(40)
                        ->helperText('Must match ERP_NODE_ID on that server, e.g. B01'),
                    TextInput::make('name')->required()->maxLength(80)->placeholder('Branch A server'),
                ])
                ->action(function (array $data) {
                    $r = Erp::run(fn ($actor) => app(NodeSetup::class)->addBranchNode($actor, $data['node_id'], $data['name']));
                    Notification::make()->success()->persistent()->title('Branch server registered')
                        ->body('On the branch server run: php artisan erp:node:join '.url('/')." {$r['hq_node_id']} {$r['tenant_id']} — and paste this secret when asked (shown once): {$r['secret']}")
                        ->send();
                }),
            Action::make('importSync')->label('Import sync file')->icon(Heroicon::OutlinedArrowUpTray)
                ->schema([FileUpload::make('file')->label('.erpsync file from another server')->required()->disk('local')->directory('sync-imports')->maxSize(512000)])
                ->action(function (array $data) {
                    $content = Storage::disk('local')->get($data['file']);
                    Storage::disk('local')->delete($data['file']);
                    $r = Erp::run(fn () => app(SyncFile::class)->import($content));
                    Notification::make()->success()->title("Imported {$r['received']} change(s) from {$r['peer']->name}")
                        ->body("Applied {$r['applied']}, waiting for review {$r['staged']}, failed {$r['failed']}.")->send();
                }),
            Action::make('importTill')->label('Import till file')->icon(Heroicon::OutlinedComputerDesktop)
                ->modalDescription('For a till that is being reinstalled or replaced before it could send its sales: on the till, use “Export unsent sales”, then load the file here.')
                ->schema([FileUpload::make('file')->label('Till queue file (.json)')->required()->disk('local')->directory('till-imports')->maxSize(102400)])
                ->action(function (array $data) {
                    $content = Storage::disk('local')->get($data['file']);
                    Storage::disk('local')->delete($data['file']);
                    $r = Erp::run(fn ($actor) => app(TillFileImport::class)->import($actor, $content));
                    $by = collect($r['results'])->countBy('status');
                    Notification::make()->success()->title("{$r['device']->name}: ".($by['accepted'] ?? 0).' applied, '.($by['duplicate'] ?? 0).' already here, '.($by['rejected'] ?? 0).' refused')
                        ->body('Refused items are in the Review queue.')->send();
                }),
        ];
    }
}
