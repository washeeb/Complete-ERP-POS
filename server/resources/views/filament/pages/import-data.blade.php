<x-filament-panels::page>
    @php($i = $this->importer())
    @php($ctx = $i->contextType())
    <x-filament::section heading="1. What are you importing?">
        <div class="im-grid">
            <label>Import <x-filament::input.wrapper><x-filament::input.select wire:model.live="type">
                @foreach ($this->types() as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper></label>

            @if ($ctx === 'stock_count')
                <label>Stock count <x-filament::input.wrapper><x-filament::input.select wire:model.live="context">
                    <option value="">Choose an open count…</option>
                    @foreach ($this->contexts() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></label>
            @elseif ($ctx === 'stock_adjustment')
                <label>Into <x-filament::input.wrapper><x-filament::input.select wire:model.live="context">
                    <option value="">A new adjustment…</option>
                    @foreach ($this->contexts() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></label>
                @if ($context === '')
                    <label>Warehouse <x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouseId">
                        @foreach ($this->warehouses() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper></label>
                    <label>Reason <x-filament::input.wrapper><x-filament::input.select wire:model="reasonCode">
                        @foreach ($this->reasons() as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper></label>
                    <label>Category on the stock sheet <x-filament::input.wrapper><x-filament::input.select wire:model="categoryId">
                        <option value="">All products</option>
                        @foreach ($this->categories() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper></label>
                @endif
            @endif
        </div>
        <p class="im-desc">{{ $i->description() }}</p>
    </x-filament::section>

    <x-filament::section heading="2. Get the file ready" description="Start from the template, or from your current data, and fill it in Excel or Google Sheets. Upload it as Excel (.xlsx) or CSV.">
        <div class="im-buttons">
            <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadTemplate">Download template (CSV)</x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-book-open" wire:click="downloadGuide">Column guide (PDF)</x-filament::button>
            @if (in_array($type, ['products', 'prices', 'customers', 'suppliers'], true))
                <x-filament::button color="gray" icon="heroicon-o-table-cells" wire:click="downloadCurrent('csv')">Download current data to edit (CSV)</x-filament::button>
            @elseif ($ctx === 'stock_adjustment')
                <x-filament::button color="gray" icon="heroicon-o-table-cells" wire:click="downloadCurrent('csv')">Stock sheet — current stock standing (CSV)</x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-printer" wire:click="downloadCurrent('pdf')">Stock sheet (PDF)</x-filament::button>
            @elseif ($ctx === 'stock_count')
                <x-filament::button color="gray" icon="heroicon-o-table-cells" wire:click="downloadCurrent('csv')">Count sheet (CSV)</x-filament::button>
                <x-filament::button color="gray" icon="heroicon-o-printer" wire:click="downloadCurrent('pdf')">Count sheet (PDF)</x-filament::button>
            @endif
        </div>
        <details class="im-cols"><summary>Columns in this file</summary>
            <table class="im-t"><thead><tr><th>Column</th><th>Required</th><th>What to put in it</th><th>Example</th></tr></thead><tbody>
            @foreach ($i->columns() as $c)
                <tr><td><code>{{ $c['name'] }}</code></td><td>{{ $c['required'] === true ? 'Yes' : ($c['required'] === 'new' ? 'New records' : '') }}</td><td>{{ $c['help'] }}</td><td>{{ $c['example'] }}</td></tr>
            @endforeach
            </tbody></table>
        </details>
    </x-filament::section>

    <x-filament::section heading="3. Upload, check, import">
        <div class="im-grid">
            <label>File (Excel .xlsx or CSV) <input type="file" wire:model="file" accept=".csv,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="im-file"></label>
            <div wire:loading wire:target="file" class="im-desc">Uploading…</div>
            @error('file')<p class="im-err">{{ $message }}</p>@enderror
        </div>
        @if ($preview)
            @if ($this->unmatched())
                <div class="im-map">
                    <p class="im-desc"><strong>Match columns:</strong> these columns were not found under their own name. Choose which of your columns holds each one (or leave it empty).</p>
                    @foreach ($this->unmatched() as $col)
                        <label><code>{{ $col }}</code>
                            <x-filament::input.wrapper><x-filament::input.select wire:model.live="mapping.{{ $col }}">
                                <option value="">— not in my file —</option>
                                @foreach ($preview['headings'] as $h)<option value="{{ $h }}">{{ $h }}</option>@endforeach
                            </x-filament::input.select></x-filament::input.wrapper>
                        </label>
                    @endforeach
                </div>
            @endif
            <details class="im-cols" open><summary>Preview: first {{ count($preview['rows']) }} of {{ $preview['total'] }} row(s)</summary>
                <div style="overflow-x:auto"><table class="im-t"><thead><tr>@foreach ($preview['headings'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead><tbody>
                @foreach ($preview['rows'] as $r)<tr>@foreach ($preview['headings'] as $h)<td>{{ $r[$h] ?? '' }}</td>@endforeach</tr>@endforeach
                </tbody></table></div>
            </details>
        @endif
        <div class="im-opts">
            <label><input type="checkbox" wire:model="skipErrors"> Skip rows with errors (import the good rows; otherwise nothing is imported while any row has an error)</label>
            @if ($type === 'products')<label><input type="checkbox" wire:model="createMissing"> Create missing categories and brands</label>@endif
        </div>
        <div class="im-buttons">
            <x-filament::button color="gray" icon="heroicon-o-magnifying-glass" wire:click="check" wire:loading.attr="disabled">Check file (nothing is saved)</x-filament::button>
            <x-filament::button icon="heroicon-o-arrow-up-tray" wire:click="import" wire:loading.attr="disabled"
                wire:confirm="Import this file now? Every row is recorded under your name in the audit log.">Import</x-filament::button>
        </div>

        @if ($r = $this->last())
            <div class="im-result">
                <strong>{{ \App\Models\DataImport::STATUSES[$r->status] }}</strong> — {{ $r->file_name }}: {{ $r->rows_total }} row(s),
                @foreach (['created' => 'new', 'updated' => 'updated', 'added' => 'added', 'unchanged' => 'unchanged / skipped'] as $k => $label)
                    @if (($r->summary[$k] ?? 0) > 0){{ $r->summary[$k] }} {{ $label }}, @endif
                @endforeach
                <span class="{{ $r->rows_error ? 'im-err' : '' }}">{{ $r->rows_error }} with errors</span>.
                @if (! empty($r->summary['ignored_columns']))<br><span class="im-desc">Columns not used: {{ implode(', ', $r->summary['ignored_columns']) }}</span>@endif
                @if ($r->rows_error)<x-filament::button size="sm" color="danger" wire:click="downloadErrors('{{ $r->id }}')">Download error report (CSV)</x-filament::button>@endif
                @if ($r->status === 'imported' && $r->type === 'stock_adjustment' && $r->context_id)
                    <a class="im-link" href="{{ \App\Filament\Resources\StockAdjustments\StockAdjustmentResource::getUrl('view', ['record' => $r->context_id]) }}">Open the adjustment to approve and post it →</a>
                @elseif ($r->status === 'imported' && $r->type === 'stock_count' && $r->context_id)
                    <a class="im-link" href="{{ \App\Filament\Resources\StockCounts\StockCountResource::getUrl('view', ['record' => $r->context_id]) }}">Open the count to submit it →</a>
                @endif
            </div>
            <table class="im-t">
                <thead><tr><th>Row</th><th>Record</th><th>Result</th><th>Message</th></tr></thead>
                <tbody>
                @foreach (array_slice($r->summary['results'] ?? [], 0, 300) as $x)
                    <tr class="{{ $x['outcome'] === 'error' ? 'im-bad' : '' }}"><td>{{ $x['row'] }}</td><td>{{ $x['ref'] }}</td><td>{{ $x['outcome'] }}</td><td>{{ $x['message'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    <x-filament::section heading="Import history" collapsible>
        <table class="im-t">
            <thead><tr><th>When</th><th>Who</th><th>What</th><th>File</th><th>Result</th><th>Rows</th><th>Errors</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->history() as $h)
                <tr><td>{{ $h->created_at->format('j M Y H:i') }}</td><td>{{ $h->user_name }}</td><td>{{ \App\Domain\Imports\ImportService::importer($h->type)->label() }}</td>
                    <td>{{ $h->file_name }}</td><td>{{ \App\Models\DataImport::STATUSES[$h->status] ?? $h->status }}</td><td>{{ $h->rows_total }}</td><td>{{ $h->rows_error }}</td>
                    <td>@if ($h->rows_error)<x-filament::button size="xs" color="gray" wire:click="downloadErrors('{{ $h->id }}')">Error report</x-filament::button>@endif</td></tr>
            @empty
                <tr><td colspan="8">No imports yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <style>
        .im-grid { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; } .im-grid label { display: grid; gap: 4px; font-size: .875rem; min-width: 260px; }
        .im-desc { font-size: .85rem; opacity: .75; margin-top: 8px; } .im-buttons { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0; }
        .im-map { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; margin: 8px 0; font-size: .875rem; } .im-map > p { grid-column: 1 / -1; } .im-map label { display: grid; gap: 4px; }
        .im-opts { display: grid; gap: 4px; font-size: .875rem; margin: 8px 0; } .im-file { font-size: .875rem; }
        .im-err { color: rgb(220 38 38); } .im-result { margin: 12px 0; font-size: .9rem; } .im-link { margin-left: 8px; text-decoration: underline; }
        .im-t { width: 100%; border-collapse: collapse; font-size: .8rem; margin-top: 8px; } .im-t th, .im-t td { padding: 4px 6px; border-bottom: 1px solid color-mix(in srgb, currentColor 6%, transparent); text-align: left; vertical-align: top; }
        .im-bad td { background: rgb(239 68 68 / .15); } .im-cols { margin-top: 8px; font-size: .875rem; }
    </style>
</x-filament-panels::page>
