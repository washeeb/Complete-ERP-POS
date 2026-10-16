<?php

namespace App\Filament\Pages;

use App\Domain\Backup\BackupService;
use App\Domain\Backup\Destinations;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/** Automatic encrypted backups: where they go, how the last ones went, and checks that they restore. */
class Backups extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Backups';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.backups';

    public static function canAccess(): bool
    {
        return Erp::can('admin.settings.manage');
    }

    public function runs()
    {
        return BackupRun::latest('started_at')->limit(20)->get();
    }

    public function destinations()
    {
        return BackupDestination::orderBy('name')->get();
    }

    public function passphraseSet(): bool
    {
        return mb_strlen((string) config('backup.passphrase')) >= 12;
    }

    /** @return list<Field> */
    private static function destinationForm(): array
    {
        $fields = [
            TextInput::make('name')->required()->maxLength(80)->helperText('e.g. "USB drive A", "Backblaze"'),
            Select::make('type')->options(Destinations::TYPES)->required()->live(),
        ];
        foreach (Destinations::FIELDS as $type => $list) {
            foreach ($list as $key => [$label, $secret]) {
                $field = $key === 'private_key' ? Textarea::make("config.{$type}.{$key}")->rows(3) : TextInput::make("config.{$type}.{$key}")->password($secret)->revealable($secret)->autocomplete($secret ? 'new-password' : null);
                $fields[] = $field->label($label)->visible(fn (Get $get) => $get('type') === $type);
            }
        }

        return [...$fields, TextInput::make('keep')->label('Keep the newest … backups there')->numeric()->minValue(1)->maxValue(365)->default(14), Toggle::make('is_active')->label('In use')->default(true)];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')->label('Back up now')->icon(Heroicon::OutlinedPlay)->requiresConfirmation()
                ->modalDescription('Makes an encrypted backup and copies it to every destination. It can take a few minutes on a large database.')
                ->action(function () {
                    @set_time_limit(3600);
                    $run = Erp::run(fn ($actor) => app(BackupService::class)->run($actor->name));
                    if ($run) {
                        Notification::make()->title("Backup {$run->status}: ".number_format($run->size_bytes / 1048576, 1).' MB')->body($run->error)
                            ->{$run->status === 'ok' ? 'success' : 'warning'}()->send();
                    }
                }),
            Action::make('verify')->label('Check the newest backup')->icon(Heroicon::OutlinedShieldCheck)->color('gray')
                ->action(function () {
                    @set_time_limit(3600);
                    $run = Erp::run(fn () => app(BackupService::class)->verifyLatest());
                    if ($run) {
                        Notification::make()->title($run->verify_result)->{str_starts_with((string) $run->verify_result, 'FAILED') ? 'danger' : 'success'}()->send();
                    }
                }),
            Action::make('add')->label('Add destination')->icon(Heroicon::OutlinedPlus)->color('gray')->schema(self::destinationForm())
                ->action(fn (array $data) => $this->saveDestination(new BackupDestination, $data)),
        ];
    }

    public function editDestinationAction(): Action
    {
        return Action::make('editDestination')->label('Change')->schema(self::destinationForm())
            ->fillForm(fn (array $arguments) => ($d = BackupDestination::find($arguments['id'])) ? ['name' => $d->name, 'type' => $d->type, 'keep' => $d->keep, 'is_active' => $d->is_active,
                // secrets are never shown: their boxes start empty (empty = keep)
                'config' => [$d->type => array_merge(array_map(fn () => '', $d->config), array_filter($d->config, fn ($v, $k) => ! (Destinations::FIELDS[$d->type][$k][1] ?? false), ARRAY_FILTER_USE_BOTH))]] : [])
            ->action(fn (array $data, array $arguments) => $this->saveDestination(BackupDestination::findOrFail($arguments['id']), $data));
    }

    private function saveDestination(BackupDestination $d, array $data): void
    {
        $type = $data['type'];
        $old = $d->exists && $d->type === $type ? ($d->config ?? []) : [];
        $config = [];
        foreach (array_keys(Destinations::FIELDS[$type]) as $k) {
            $v = trim((string) ($data['config'][$type][$k] ?? ''));
            $config[$k] = $v !== '' ? $v : ($old[$k] ?? ''); // empty secret = keep the saved one
        }
        try {
            Destinations::test($type, $config);
        } catch (DomainException $e) {
            Notification::make()->title('Not saved: the test copy failed')->body($e->getMessage())->danger()->send();

            return;
        }
        Erp::run(function ($actor) use ($d, $data, $type, $config) {
            DB::transaction(function () use ($actor, $d, $data, $type, $config) {
                $d->forceFill(['name' => $data['name'], 'type' => $type, 'config' => $config, 'keep' => (int) ($data['keep'] ?? 14), 'is_active' => (bool) ($data['is_active'] ?? true)])->save();
                app(AuditLog::class)->record($actor, 'backup_destination.saved', $d, ['type' => [null, $type], 'settings' => [null, 'changed']]);
            });

            return true;
        }, 'Destination saved — a test copy was written and removed');
    }

    public function download(string $id): ?BinaryFileResponse
    {
        $run = BackupRun::findOrFail($id);
        $file = app(BackupService::class)->localDir().DIRECTORY_SEPARATOR.$run->file_name;
        if (! is_file($file)) {
            Notification::make()->title('That backup is no longer on the server (only the newest are kept here).')->warning()->send();

            return null;
        }
        Erp::run(fn ($actor) => DB::transaction(fn () => app(AuditLog::class)->record($actor, 'backup.downloaded', $run, ['file' => [null, $run->file_name]])) ?? true);

        return response()->download($file);
    }
}
