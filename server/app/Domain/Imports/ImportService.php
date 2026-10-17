<?php

namespace App\Domain\Imports;

use App\Domain\Imports\Importers\AccountsImporter;
use App\Domain\Imports\Importers\CustomerBalancesImporter;
use App\Domain\Imports\Importers\CustomersImporter;
use App\Domain\Imports\Importers\GiftCardsImporter;
use App\Domain\Imports\Importers\LoyaltyPointsImporter;
use App\Domain\Imports\Importers\OpeningBalancesImporter;
use App\Domain\Imports\Importers\OpeningStockImporter;
use App\Domain\Imports\Importers\PricesImporter;
use App\Domain\Imports\Importers\ProductsImporter;
use App\Domain\Imports\Importers\ProductsUpdateImporter;
use App\Domain\Imports\Importers\StockAdjustmentImporter;
use App\Domain\Imports\Importers\StockCountImporter;
use App\Domain\Imports\Importers\SupplierBalancesImporter;
use App\Domain\Imports\Importers\SuppliersImporter;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Models\DataImport;
use App\Models\User;
use App\Security\Authorizer;
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
        'stock_count' => StockCountImporter::class,
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
    public function run(Actor $actor, string $type, string $path, string $fileName, array $options = [], ?Model $context = null, bool $commit = false, array $mapping = []): DataImport
    {
        $importer = self::importer($type);
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, $importer->permission())) {
            throw new AccessDenied;
        }
        $options += $importer->optionDefaults();
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
        if ($importer->inBatches()) {
            // All or nothing: check every row first (nothing kept), then import only a clean file.
            $check = $commit && empty($options['skip_errors']);
            [$results, $errors, $counts] = $this->batches($actor, $importer, $type, $csv['rows'], $options, $context, $commit && ! $check);
            $keep = $commit && ($errors === [] || ! empty($options['skip_errors']));
            if ($check && $keep) {
                // Rows that passed the check but fail now (someone changed the data in between) are reported, not imported.
                [$results, $errors, $counts] = $this->batches($actor, $importer, $type, $csv['rows'], $options, $context, true);
            }
        } else {
            [$results, $errors, $counts, $keep, $contextId] = $this->oneTransaction($actor, $importer, $type, $csv['rows'], $options, $context, $commit);
        }

        $status = ! $commit ? 'checked' : ($keep ? 'imported' : 'failed');

        return DB::transaction(function () use ($actor, $type, $fileName, $path, $status, $options, $contextId, $givenContextId, $csv, $errors, $counts, $ignored, $results, $importer) {
            $import = DataImport::create(['id' => (string) Str::uuid7(), 'tenant_id' => $actor->tenantId, 'type' => $type, 'file_name' => mb_substr($fileName, 0, 255),
                'stored_path' => $path, 'status' => $status, 'options' => $options, 'context_id' => $status === 'imported' ? $contextId : $givenContextId,
                'rows_total' => count($csv['rows']), 'rows_ok' => count($csv['rows']) - count($errors), 'rows_error' => count($errors),
                'summary' => $counts + ['ignored_columns' => $ignored, 'results' => array_slice($results, 0, 500)],
                'errors' => array_slice($errors, 0, 1000), 'user_id' => $actor->id, 'user_name' => $actor->name, 'completed_at' => now()]);
            $this->audit->record($actor, $status === 'imported' ? 'data.imported' : 'data.import_checked', $import, [
                'type' => [null, $importer->label()], 'file' => [null, $fileName], 'rows' => [null, count($csv['rows'])], 'errors' => [null, count($errors)],
            ] + array_map(fn ($n) => [null, $n], array_filter($counts)));

            return $import;
        });
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
    private function applyRows(Actor $actor, Importer $importer, string $type, array $rows, array $options, ?Model $context, array &$results, array &$errors, array &$counts): void
    {
        foreach ($rows as $r) {
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
