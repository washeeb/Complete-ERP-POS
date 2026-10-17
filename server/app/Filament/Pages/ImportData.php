<?php

namespace App\Filament\Pages;

use App\Domain\Imports\Importer;
use App\Domain\Imports\ImportService;
use App\Domain\Imports\ImportSources;
use App\Domain\Imports\SheetFile;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Models\Category;
use App\Models\DataImport;
use App\Models\GoLivePlan;
use App\Models\Industry;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Support\Exports\Exporter;
use App\Support\IndustryContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use UnitEnum;

/**
 * Import data from CSV files: pick what to import, download the template (or your current data to edit), upload,
 * **Check file** (nothing is saved), then **Import**. Every row goes through the same checks as the screens.
 */
class ImportData extends Page
{
    use ExportsPage, WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Import data (CSV / Excel)';

    protected static ?string $title = 'Import data (CSV / Excel)';

    protected static ?string $slug = 'import-data';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.import-data';

    #[Url]
    public string $type = '';

    #[Url]
    public string $context = ''; // stock count / adjustment id

    public string $warehouseId = '';

    public string $reasonCode = 'correction';

    public string $categoryId = '';

    /** Where the file comes from: our template, QuickBooks or another till system (fills in "match columns"). */
    #[Url]
    public string $source = 'excel';

    /** The go-live date opening balances are "as of". */
    public string $asOf = '';

    /** Which industry the rows are for (industry imports such as products): an industry id, "shared", or not chosen yet. */
    #[Url]
    public string $industry = '';

    public bool $skipErrors = false;

    public bool $createMissing = true;

    /** Products — create new: allow a new product whose name another product already has (e.g. two strengths). */
    public bool $allowSameName = false;

    public $file = null;

    public ?string $lastId = null;

    /** @var array<string, string> expected column => the file's own heading ("match columns") */
    public array $mapping = [];

    /** @var array{headings: list<string>, rows: list<array>, total: int}|null the first rows of the uploaded file */
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        $user = Erp::user();

        return $user !== null && app(ImportService::class)->available($user) !== [];
    }

    public function mount(): void
    {
        $types = $this->types();
        if (! isset($types[$this->type])) {
            $this->type = (string) array_key_first($types);
        }
        $this->warehouseId = $this->warehouseId ?: (string) (array_key_first($this->warehouses()) ?? '');
        if (! isset(ImportSources::LABELS[$this->source])) {
            $this->source = 'excel';
        }
        $this->asOf = $this->asOf ?: (GoLivePlan::first()?->go_live_on?->toDateString() ?? now()->toDateString());
        $this->industry = $this->industry ?: (string) IndustryContext::selected(); // the industry chosen at the top, if any
    }

    /** @return array<string, string> the industries an industry import can go to, and "shared" */
    public function industries(): array
    {
        return Industry::licensed()->orderBy('sort')->pluck('name', 'id')->all() + ['shared' => 'Shared by all industries'];
    }

    /** The import belongs to one industry and the company has industries: the page asks which. */
    public function asksIndustry(): bool
    {
        return $this->importer()->industryScoped() && Industry::licensed()->exists();
    }

    public function sources(): array
    {
        return ImportSources::LABELS;
    }

    public function updatedSource(): void
    {
        if ($this->file) {
            $this->updatedFile();
        }
    }

    public function updatedType(): void
    {
        $this->context = '';
        $this->lastId = null;
    }

    public function types(): array
    {
        return app(ImportService::class)->available(Erp::user());
    }

    public function importer(): Importer
    {
        return ImportService::importer($this->type);
    }

    public function warehouses(): array
    {
        $ids = Erp::branchIds('stock.adjust') ?? Erp::branchIds('stock.count');

        return Warehouse::when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }

    public function categories(): array
    {
        return Category::orderBy('name')->pluck('name', 'id')->all();
    }

    public function reasons(): array
    {
        return InventoryService::ADJUSTMENT_REASONS;
    }

    /** The records a file can go into (open counts, draft adjustments). */
    public function contexts(): array
    {
        return match ($this->importer()->contextType()) {
            'stock_count' => StockCount::with('warehouse')->where('status', 'counting')->orderByDesc('started_at')->get()
                ->mapWithKeys(fn ($c) => [$c->id => "{$c->number} · {$c->warehouse->code} · started {$c->started_at->format('j M H:i')}"])->all(),
            'stock_adjustment' => StockAdjustment::with('warehouse')->where('status', 'draft')->orderByDesc('created_at')->get()
                ->mapWithKeys(fn ($a) => [$a->id => "{$a->number} · {$a->warehouse->code} · {$this->reasons()[$a->reason_code]}"])->all(),
            default => [],
        };
    }

    public function contextModel(): ?Model
    {
        if ($this->context === '') {
            return null;
        }

        return match ($this->importer()->contextType()) {
            'stock_count' => StockCount::find($this->context),
            'stock_adjustment' => StockAdjustment::find($this->context),
            default => null,
        };
    }

    public function options(): array
    {
        return ['skip_errors' => $this->skipErrors, 'create_missing' => $this->createMissing, 'allow_same_name' => $this->allowSameName, 'warehouse_id' => $this->warehouseId ?: null,
            'reason_code' => $this->reasonCode, 'category_id' => $this->categoryId ?: null, 'source' => $this->source, 'as_of' => $this->asOf ?: now()->toDateString(),
            // Which industry: as chosen on the page (new products); for an import that does not ask (updates), the industry
            // chosen at the top of the back office, or every product; "shared" only when the company has no industries.
            'industry' => $this->asksIndustry() ? $this->industry
                : ($this->importer()->industryScoped() ? 'shared' : (string) IndustryContext::selected())];
    }

    /** Headings and one example row. */
    public function downloadTemplate(string $format = 'csv')
    {
        $i = $this->importer();
        $sheet = [['title' => $i->label(), 'headings' => $i->headings(), 'rows' => [array_column($i->columns(), 'example')]]];

        return Erp::run(fn ($actor) => app(Exporter::class)->{$format === 'xlsx' ? 'xlsx' : 'csv'}($actor, "{$i->label()} template", $sheet));
    }

    /** What each column means, as a PDF to print. */
    public function downloadGuide()
    {
        $i = $this->importer();

        return Erp::run(fn ($actor) => app(Exporter::class)->pdf($actor, "{$i->label()} — column guide", [['title' => $i->label(), 'note' => $i->description(),
            'headings' => ['Column', 'Required', 'What to put in it', 'Example'],
            'rows' => array_map(fn ($c) => [$c['name'], $c['required'] === true ? 'Yes' : ($c['required'] === 'new' ? 'For new records' : 'No'), $c['help'], $c['example']], $i->columns())]],
            [], 'portrait'));
    }

    /** Current data in the import layout (products, prices, customers, suppliers; the stock sheet; a count sheet). */
    public function downloadCurrent(string $format = 'csv')
    {
        $i = $this->importer();
        $rows = $i->exportRows(Erp::actor(), $this->options(), $this->contextModel());
        if ($rows === null) {
            Notification::make()->warning()->title($i->contextType() === 'stock_count' ? 'Choose the stock count first.' : 'Choose the warehouse first.')->send();

            return null;
        }
        $name = match ($this->type) {
            'stock_adjustment' => 'Stock sheet '.(Warehouse::find($this->contextModel()?->warehouse_id ?? $this->warehouseId)?->code ?? ''),
            'stock_count' => 'Count sheet '.($this->contextModel()?->number ?? ''),
            default => $i->label().' (current data)',
        };
        $sheet = [['title' => $name, 'headings' => $i->headings(), 'rows' => $rows]];

        return Erp::run(fn ($actor) => match ($format) {
            'pdf' => app(Exporter::class)->pdf($actor, $name, $sheet),
            'xlsx' => app(Exporter::class)->xlsx($actor, $name, $sheet),
            default => app(Exporter::class)->csv($actor, $name, $sheet),
        });
    }

    /** A file was chosen: read its headings and first rows, and suggest which of its columns are which. */
    public function updatedFile(): void
    {
        $this->preview = null;
        $this->mapping = [];
        if (! $this->file) {
            return;
        }
        try {
            $data = SheetFile::read($this->file->getRealPath(), $this->file->getClientOriginalName());
        } catch (DomainException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }
        $this->preview = ['headings' => $data['headings'], 'rows' => array_map(fn ($r) => $r['values'], array_slice($data['rows'], 0, 5)), 'total' => count($data['rows'])];
        $known = ImportSources::suggest($this->source, $this->type, $data['headings'], $this->importer()->headings()); // QuickBooks / other till names
        foreach ($this->importer()->columns() as $c) {
            if (isset($known[$c['name']])) {
                $this->mapping[$c['name']] = $known[$c['name']];
            } elseif (! in_array($c['name'], $data['headings'], true)) {
                // A heading that contains the column name (or the other way round) is probably it: "item_sku" for "sku".
                $guess = collect($data['headings'])->first(fn ($h) => ! in_array($h, $this->importer()->headings(), true) && (str_contains($h, $c['name']) || str_contains($c['name'], $h)));
                $this->mapping[$c['name']] = (string) $guess;
            }
        }
    }

    /** Expected columns the file does not have under their own name. */
    public function unmatched(): array
    {
        return array_keys($this->mapping);
    }

    public function check(): void
    {
        $this->runImport(false);
    }

    public function import(): void
    {
        $this->runImport(true);
    }

    private function runImport(bool $commit): void
    {
        $this->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx']], ['file.required' => 'Choose the file first.', 'file.mimes' => 'The file must be CSV or Excel (.xlsx).']);
        if ($this->asksIndustry() && ! isset($this->industries()[$this->industry])) {
            $this->addError('industry', 'Choose which industry these are for.');

            return;
        }
        @set_time_limit(1800); // a few thousand products take minutes, beyond PHP's usual 30 seconds per request
        $stored = $this->file->store('imports', 'local');
        $path = Storage::disk('local')->path($stored);
        try {
            $import = Erp::run(fn ($actor) => app(ImportService::class)->run($actor, $this->type, $path, $this->file->getClientOriginalName(), $this->options(), $this->contextModel(), $commit, $this->mapping));
        } catch (Halt) {
            return;
        }
        $this->lastId = $import->id;
        $n = $import->rows_error;
        $note = Notification::make()->title(match ($import->status) {
            'checked' => $n ? "Checked: {$n} row(s) have errors — nothing was imported" : "Checked: all {$import->rows_total} row(s) are fine — nothing was imported yet",
            'imported' => "Imported {$import->rows_ok} row(s)".($n ? ", skipped {$n} with errors" : ''),
            default => "Not imported: {$n} row(s) have errors. Fix them (download the error report) or tick “Skip rows with errors”.",
        });
        ($import->status === 'imported' || ($import->status === 'checked' && ! $n) ? $note->success() : $note->danger())->send();
        if ($import->status === 'imported') {
            $this->file = null;
            $this->preview = null;
            $this->mapping = [];
        }
    }

    public function last(): ?DataImport
    {
        return $this->lastId ? DataImport::find($this->lastId) : null;
    }

    public function history()
    {
        return DataImport::orderByDesc('created_at')->limit(20)->get();
    }

    /** The rows that failed, with the reason in the first column, to fix and import again. */
    public function downloadErrors(string $id)
    {
        $import = DataImport::findOrFail($id);
        $headings = array_keys($import->errors[0]['values'] ?? []);

        return Erp::run(fn ($actor) => app(Exporter::class)->csv($actor, 'Import errors '.$import->file_name, [['title' => 'Errors',
            'headings' => ['row', 'error', ...$headings],
            'rows' => array_map(fn ($e) => [$e['row'], $e['message'], ...array_values(array_map(fn ($h) => $e['values'][$h] ?? '', $headings))], $import->errors)]]));
    }
}
