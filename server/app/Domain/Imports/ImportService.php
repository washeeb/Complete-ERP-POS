<?php

namespace App\Domain\Imports;

use App\Domain\Imports\Importers\AccountsImporter;
use App\Domain\Imports\Importers\CustomerBalancesImporter;
use App\Domain\Imports\Importers\CustomersImporter;
use App\Domain\Imports\Importers\GiftCardsImporter;
use App\Domain\Imports\Importers\LoyaltyPointsImporter;
use App\Domain\Imports\Importers\OpeningBalancesImporter;
use App\Domain\Imports\Importers\OpeningBatchesImporter;
use App\Domain\Imports\Importers\OpeningStockImporter;
use App\Domain\Imports\Importers\PricesImporter;
use App\Domain\Imports\Importers\ProductsImporter;
use App\Domain\Imports\Importers\ProductsUpdateImporter;
use App\Domain\Imports\Importers\StockAdjustmentImporter;
use App\Domain\Imports\Importers\StockCountImporter;
use App\Domain\Imports\Importers\SupplierBalancesImporter;
use App\Domain\Imports\Importers\SuppliersImporter;
use App\Domain\Imports\Importers\UnitChangesImporter;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ImportData;
use App\Models\DataImport;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Progress;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Data import from CSV files: **check** (every row is run through the real services, then everything is undone, so the
 * check finds exactly what a real import would refuse) and **import**. By default a file is imported all or nothing;
 * "skip rows with errors" imports the good rows and reports the rest. Each row is its own savepoint, so one bad row never
 * leaves half a record behind. Every run is logged (Data → Import history) and in the audit log.
 *
 * Rows go in batches (BATCH rows, each batch its own transaction) so a file of thousands of rows never holds the audit
 * log, and with it every other change in the system, for minutes. All or nothing then means: the whole file is checked
 * first, and imported only when no row has an error.
 */
final class ImportService
{
    public const TYPES = [
        'products' => ProductsImporter::class,
        'products_update' => ProductsUpdateImporter::class,
        'prices' => PricesImporter::class,
        'customers' => CustomersImporter::class,
        'suppliers' => SuppliersImporter::class,
        'opening_stock' => OpeningStockImporter::class,
        'stock_adjustment' => StockAdjustmentImporter::class,
        'opening_batches' => OpeningBatchesImporter::class,
        'stock_count' => StockCountImporter::class,
        'unit_changes' => UnitChangesImporter::class,
        // Data migration (Phase 14): the books and balances on the go-live date.
        'accounts' => AccountsImporter::class,
        'opening_balances' => OpeningBalancesImporter::class,
        'customer_balances' => CustomerBalancesImporter::class,
        'supplier_balances' => SupplierBalancesImporter::class,
        'loyalty_points' => LoyaltyPointsImporter::class,
        'gift_cards' => GiftCardsImporter::class,
    ];

    /** Rows per transaction: a few seconds at most, so sales and other changes get through in between. */
    public const BATCH = 50;

    /** Files with more rows than this run in the background (erp:imports), never inside a web request. */
    public static int $backgroundFrom = 500;

    public function __construct(private readonly Authorizer $authz, private readonly AuditLog $audit) {}

    public static function importer(string $type): Importer
    {
        $class = self::TYPES[$type] ?? throw new DomainException('Unknown kind of import.');

        return app($class);
    }

    /** @return array<string, string> type => label, for the types this person may use */
    public function available(User $user): array
    {
        $out = [];
        foreach (array_keys(self::TYPES) as $type) {
            $i = self::importer($type);
            if ($this->authz->allowsAnywhere($user, $i->permission())) {
                $out[$type] = $i->label();
            }
        }

        return $out;
    }

    /**
     * @param  array{skip_errors?: bool}  $options
     * @param  array<string, string>  $mapping  expected column => the file's own heading (the "match columns" step)
     */
    /** The last successful import of exactly this file (same type and, for a stock count or adjustment, the same one). */
    public static function alreadyImported(string $type, ?string $hash, ?string $contextId): ?DataImport
    {
        return $hash ? DataImport::where('type', $type)->where('file_hash', $hash)->where('status', 'imported')
            ->when($contextId, fn ($q) => $q->where('context_id', $contextId))->latest('completed_at')->first() : null;
    }

    public function run(Actor $actor, string $type, string $path, string $fileName, array $options = [], ?Model $context = null, bool $commit = false, array $mapping = [], ?DataImport $record = null): DataImport
    {
        $this->progressUser = $actor->id;
        $importer = self::importer($type);
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, $importer->permission())) {
            throw new AccessDenied;
        }
        $options += $importer->optionDefaults();
        $hash = hash_file('sha256', $path) ?: null;
        if ($commit && empty($options['import_again']) && ($done = self::alreadyImported($type, $hash, $context?->getKey()))) {
            throw new DomainException("This file was already imported on {$done->completed_at?->format('j M Y H:i')} by {$done->user_name} ({$done->rows_ok} rows). "
                .'Tick "Import it again" only if you really mean to add it a second time.', 'already_imported');
        }
        $csv = SheetFile::read($path, $fileName, $mapping); // CSV or Excel
        $csv = ImportSources::prepare((string) ($options['source'] ?? 'excel'), $type, $csv); // QuickBooks / other till exports
        $missing = array_diff(array_column(array_filter($importer->columns(), fn ($c) => $c['required'] === true), 'name'), $csv['headings']);
        if ($missing) {
            throw new DomainException('The file is missing the column(s): '.implode(', ', $missing).'. Download the template to see the layout.');
        }
        $known = $importer->headings();
        $ignored = array_values(array_filter($csv['headings'], fn ($h) => ! in_array($h, $known, true) && ! $this->dynamicColumn($importer, $h)));

        $results = [];
        $errors = [];
        $counts = ['created' => 0, 'updated' => 0, 'added' => 0, 'unchanged' => 0];
        $givenContextId = $context?->getKey();
        $contextId = $givenContextId;
        $total = count($csv['rows']);
        try {
            if ($importer->inBatches()) {
                // All or nothing: check every row first (nothing kept), then import only a clean file.
                $check = $commit && empty($options['skip_errors']);
                $this->pass(($commit && ! $check ? 'Importing' : 'Checking')." {$fileName}", $total);
                [$results, $errors, $counts] = $this->batches($actor, $importer, $type, $csv['rows'], $options, $context, $commit && ! $check);
                $keep = $commit && ($errors === [] || ! empty($options['skip_errors']));
                if ($check && $keep) {
                    // Rows that passed the check but fail now (someone changed the data in between) are reported, not imported.
                    $this->pass("Importing {$fileName}", $total);
                    [$results, $errors, $counts] = $this->batches($actor, $importer, $type, $csv['rows'], $options, $context, true);
                }
            } else {
                $this->pass(($commit ? 'Importing' : 'Checking')." {$fileName}", $total);
                [$results, $errors, $counts, $keep, $contextId] = $this->oneTransaction($actor, $importer, $type, $csv['rows'], $options, $context, $commit);
            }
        } finally {
            Progress::finish($actor->id);
        }

        $status = ! $commit ? 'checked' : ($keep ? 'imported' : 'failed');

        return DB::transaction(function () use ($actor, $type, $fileName, $path, $status, $options, $contextId, $givenContextId, $csv, $errors, $counts, $ignored, $results, $importer, $hash, $record, $mapping) {
            $values = ['tenant_id' => $actor->tenantId, 'type' => $type, 'file_name' => mb_substr($fileName, 0, 255),
                'stored_path' => $path, 'file_hash' => $hash, 'status' => $status, 'options' => $options + ($mapping ? ['_mapping' => $mapping] : []), 'context_id' => $status === 'imported' ? $contextId : $givenContextId,
                'rows_total' => count($csv['rows']), 'rows_ok' => count($csv['rows']) - count($errors), 'rows_error' => count($errors),
                'summary' => $counts + ['ignored_columns' => $ignored, 'results' => array_slice($results, 0, 500)],
                'errors' => array_slice($errors, 0, 1000), 'user_id' => $actor->id, 'user_name' => $actor->name, 'completed_at' => now()];
            $import = $record ? tap($record->forceFill($values))->save() : DataImport::create(['id' => (string) Str::uuid7()] + $values);
            $this->audit->record($actor, $status === 'imported' ? 'data.imported' : 'data.import_checked', $import, [
                'type' => [null, $importer->label()], 'file' => [null, $fileName], 'rows' => [null, count($csv['rows'])], 'errors' => [null, count($errors)],
            ] + array_map(fn ($n) => [null, $n], array_filter($counts)));

            return $import;
        });
    }

    /**
     * A large file waits for the background run (erp:imports) instead of holding a web request open for minutes. The
     * person sees its progress on the import page and gets a notification when it is done.
     */
    public function queue(Actor $actor, string $type, string $path, string $fileName, array $options, ?string $contextId, bool $commit, array $mapping, int $rows): DataImport
    {
        $importer = self::importer($type);
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, $importer->permission())) {
            throw new AccessDenied;
        }
        if (DataImport::whereIn('status', ['queued', 'running'])->where('file_hash', hash_file('sha256', $path) ?: null)->exists()) {
            throw new DomainException('This file is already waiting or running in the background. Its progress shows below.');
        }

        return DataImport::create(['id' => (string) Str::uuid7(), 'tenant_id' => $actor->tenantId, 'type' => $type, 'file_name' => mb_substr($fileName, 0, 255),
            'stored_path' => $path, 'file_hash' => hash_file('sha256', $path) ?: null, 'status' => 'queued', 'context_id' => $contextId,
            'options' => $options + ['_commit' => $commit, '_mapping' => $mapping], 'rows_total' => $rows, 'rows_ok' => 0, 'rows_error' => 0,
            'user_id' => $actor->id, 'user_name' => $actor->name]);
    }

    /** Runs one waiting import as the person who uploaded it, and tells them how it went. */
    public function runQueued(DataImport $record): DataImport
    {
        $record->forceFill(['status' => 'running', 'updated_at' => now()])->save();
        $user = User::withoutGlobalScopes()->find($record->user_id);
        $options = $record->options ?? [];
        $commit = (bool) ($options['_commit'] ?? false);
        $mapping = (array) ($options['_mapping'] ?? []);
        unset($options['_commit'], $options['_mapping']);
        @set_time_limit(0);
        try {
            if (! $user) {
                throw new DomainException('The person who uploaded this file no longer exists.');
            }
            auth()->setUser($user);
            $context = self::contextOf($record);
            $done = $this->run(Actor::user($user, 'web'), $record->type, $record->stored_path, $record->file_name, $options, $context, $commit, $mapping, $record);
        } catch (Throwable $e) {
            Log::error('Background import failed', ['import' => $record->id, 'error' => $e->getMessage()]);
            $msg = $e instanceof DomainException || $e instanceof AccessDenied ? $e->getMessage() : 'It stopped with an error, noted in the server log (reference '.$record->id.').';
            $record->forceFill(['status' => 'failed', 'completed_at' => now(), 'errors' => [['row' => 0, 'ref' => 'whole file', 'message' => $msg, 'values' => []]], 'rows_error' => $record->rows_total])->save();
            $done = $record;
        }
        if ($user) {
            $ok = $done->status === 'imported' || ($done->status === 'checked' && ! $done->rows_error);
            Notification::make()->title(match ($done->status) {
                'checked' => "Checked {$done->file_name}: ".($done->rows_error ? "{$done->rows_error} row(s) have errors" : 'all rows are fine'),
                'imported' => "Imported {$done->file_name}: {$done->rows_ok} row(s)".($done->rows_error ? ", {$done->rows_error} skipped" : ''),
                default => "Not imported: {$done->file_name}",
            })->body($done->status === 'checked' && ! $done->rows_error ? 'Open it to import the file: it is already on the server.' : 'Open it for the details.')
                ->actions([Action::make('open')->label('Open')->markAsRead()
                    ->url(ImportData::getUrl(['type' => $done->type, 'show' => $done->id], panel: 'admin'))])
                ->{$ok ? 'success' : 'danger'}()->sendToDatabase($user);
        }

        return $done;
    }

    /**
     * Import a file that was checked, from the copy kept on the server: no second upload, the same options and column
     * matching. Large files go to the background, as on upload.
     */
    public function importChecked(Actor $actor, DataImport $checked, bool $skipErrors = false): DataImport
    {
        if ($checked->status !== 'checked') {
            throw new DomainException('Only a checked file can be imported from here.');
        }
        if (! $checked->stored_path || ! is_file($checked->stored_path)) {
            throw new DomainException('The checked file is no longer on the server. Upload it again.');
        }
        if ($checked->rows_error && ! $skipErrors) {
            throw new DomainException("{$checked->rows_error} row(s) have errors: fix them and upload the file again, or import skipping those rows.");
        }
        if ($done = self::alreadyImported($checked->type, $checked->file_hash, $checked->context_id)) {
            throw new DomainException("This file was already imported on {$done->completed_at?->format('j M Y H:i')} by {$done->user_name}.");
        }
        $options = $checked->options ?? [];
        $mapping = (array) ($options['_mapping'] ?? []);
        unset($options['_commit'], $options['_mapping']);
        $options['skip_errors'] = $skipErrors || ! empty($options['skip_errors']);
        if ($checked->rows_total > self::$backgroundFrom) {
            return $this->queue($actor, $checked->type, $checked->stored_path, $checked->file_name, $options, $checked->context_id, true, $mapping, $checked->rows_total);
        }

        return $this->run($actor, $checked->type, $checked->stored_path, $checked->file_name, $options, self::contextOf($checked), true, $mapping);
    }

    /** The stock count or adjustment an import goes into. */
    private static function contextOf(DataImport $record): ?Model
    {
        return $record->context_id ? match (self::importer($record->type)->contextType()) {
            'stock_count' => StockCount::find($record->context_id),
            'stock_adjustment' => StockAdjustment::find($record->context_id),
            default => null,
        } : null;
    }

    /** A background import that stopped without finishing (the server restarted) is marked, so it never looks stuck. */
    public static function abandonStale(): void
    {
        DataImport::withoutGlobalScopes()->where('status', 'running')->where('updated_at', '<', now()->subHours(3))
            ->update(['status' => 'failed', 'completed_at' => now(), 'errors' => json_encode([['row' => 0, 'ref' => 'whole file', 'message' => 'It stopped before finishing (the server restarted). Nothing past the last saved batch was kept: check the products, then import again.', 'values' => []]])]);
    }

    /** The rows in batches, each its own transaction: kept when $commit, otherwise undone. */
    private function batches(Actor $actor, Importer $importer, string $type, array $rows, array $options, ?Model $context, bool $commit): array
    {
        $results = [];
        $errors = [];
        $counts = ['created' => 0, 'updated' => 0, 'added' => 0, 'unchanged' => 0];
        foreach (array_chunk($rows, self::BATCH) as $batch) {
            DB::beginTransaction();
            try {
                $this->applyRows($actor, $importer, $type, $batch, $options, $context, $results, $errors, $counts);
                $commit ? DB::commit() : DB::rollBack();
            } catch (Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }

        return [$results, $errors, $counts];
    }

    /** The whole file in one transaction, for imports whose rows build one document (prepare … finish). */
    private function oneTransaction(Actor $actor, Importer $importer, string $type, array $rows, array $options, ?Model $context, bool $commit): array
    {
        $results = [];
        $errors = [];
        $counts = ['created' => 0, 'updated' => 0, 'added' => 0, 'unchanged' => 0];
        $contextId = $context?->getKey();
        DB::beginTransaction();
        try {
            $context = $importer->prepare($actor, $options, $context);
            $contextId = $context?->getKey();
            $this->applyRows($actor, $importer, $type, $rows, $options, $context, $results, $errors, $counts);
            if ($errors === [] || ! empty($options['skip_errors'])) {
                try {
                    DB::transaction(fn () => $importer->finish($actor, $options, $context));
                } catch (DomainException|AccessDenied $e) {
                    $msg = $e instanceof AccessDenied ? 'You are not allowed to do this.' : $e->getMessage();
                    $errors[] = ['row' => 0, 'ref' => 'whole file', 'message' => $msg, 'values' => []];
                    $results[] = ['row' => 0, 'ref' => 'whole file', 'outcome' => 'error', 'message' => $msg];
                    $options['skip_errors'] = false; // a file-level failure is never skipped
                }
            }
            $keep = $commit && ($errors === [] || ! empty($options['skip_errors']));
            $keep ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [$results, $errors, $counts, $keep, $contextId];
    }

    /** Each row in its own savepoint: a bad row is reported and leaves nothing behind. */
    /** Rows done in the current pass, for "Checking 150 of 2,000". */
    private int $progressDone = 0;

    private ?string $progressUser = null;

    private function pass(string $label, int $total): void
    {
        $this->progressDone = 0;
        Progress::start($label, $total, $this->progressUser);
    }

    private function applyRows(Actor $actor, Importer $importer, string $type, array $rows, array $options, ?Model $context, array &$results, array &$errors, array &$counts): void
    {
        $this->progressUser = $actor->id;
        foreach ($rows as $r) {
            Progress::tick(++$this->progressDone, null, $actor->id);
            $ref = $importer->reference($r['values']);
            try {
                $outcome = DB::transaction(fn () => $importer->apply($actor, $r['values'], $options, $context));
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                $results[] = ['row' => $r['line'], 'ref' => $ref, 'outcome' => $outcome, 'message' => null];
            } catch (DomainException|AccessDenied $e) {
                $msg = $e instanceof AccessDenied ? 'You are not allowed to change this.' : $e->getMessage();
                $errors[] = ['row' => $r['line'], 'ref' => $ref, 'message' => $msg, 'values' => $r['values']];
                $results[] = ['row' => $r['line'], 'ref' => $ref, 'outcome' => 'error', 'message' => $msg];
            } catch (Throwable $e) {
                Log::warning('Import row failed', ['type' => $type, 'row' => $r['line'], 'error' => $e->getMessage()]);
                $msg = 'Could not be imported: '.Str::limit($e->getMessage(), 160);
                $errors[] = ['row' => $r['line'], 'ref' => $ref, 'message' => $msg, 'values' => $r['values']];
                $results[] = ['row' => $r['line'], 'ref' => $ref, 'outcome' => 'error', 'message' => $msg];
            }
        }
    }

    /** Columns an importer accepts beyond its fixed list (e.g. price_<list code> for products). */
    private function dynamicColumn(Importer $importer, string $heading): bool
    {
        return method_exists($importer, 'acceptsColumn') && $importer->acceptsColumn($heading);
    }
}
