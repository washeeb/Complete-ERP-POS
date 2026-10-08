<?php

namespace App\Filament\Pages;

use App\Domain\Reports\ReportEngine;
use App\Domain\Reports\ReportService;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Models\Branch;
use App\Models\SavedReport;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Build a report from a dataset: columns, figures, period, branch and filters; drill into any line; save and schedule it. */
class ReportBuilder extends Page
{
    use ExportsPage {
        getHeaderActions as exportActions;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Report builder';

    protected static ?string $title = 'Report builder';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.report-builder';

    #[Url]
    public ?string $report = null;

    public string $dataset = 'sales_lines';

    public array $dimensions = ['branch'];

    public array $measures = ['net', 'qty'];

    public string $period = 'this_month';

    public ?string $from = null;

    public ?string $to = null;

    public ?string $branch_id = null;

    public ?string $filterDim = null;

    public ?string $filterValue = null;

    public ?string $sort = null;

    public string $direction = 'desc';

    /** @var array<string, string> */
    public array $drill = [];

    public ?array $result = null;

    public ?string $error = null;

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('reports.build');
    }

    public function mount(): void
    {
        if ($this->report && ($r = $this->savedReport())) {
            $d = $r->definition ?? [];
            $this->dataset = $r->dataset;
            foreach (['dimensions', 'measures', 'period', 'from', 'to', 'branch_id', 'sort', 'direction'] as $k) {
                if (array_key_exists($k, $d)) {
                    $this->{$k} = $d[$k];
                }
            }
            $f = $d['filters'] ?? [];
            $this->filterDim = array_key_first($f);
            $this->filterValue = $f ? (string) reset($f) : null;
        }
        if (! array_key_exists($this->dataset, $this->datasets())) {
            $this->dataset = (string) array_key_first($this->datasets());
        }
        $this->runReport();
    }

    public function getTitle(): string
    {
        return $this->savedReport()?->name ?? 'Report builder';
    }

    private function savedReport(): ?SavedReport
    {
        $r = $this->report ? SavedReport::find($this->report) : null;

        return $r && ($r->owner_id === Erp::user()->id || $r->shared) ? $r : null;
    }

    /** Datasets this person may use. */
    public function datasets(): array
    {
        $out = [];
        foreach (ReportEngine::datasets() as $key => [$label, $perm, $companyWide]) {
            $ids = Erp::branchIds($perm);
            if (Erp::canAnywhere($perm) && (! $companyWide || $ids === null)) {
                $out[$key] = $label;
            }
        }

        return $out;
    }

    public function fields(): array
    {
        return $this->dataset ? ReportEngine::fields($this->dataset) : ['dims' => [], 'measures' => []];
    }

    public function branches(): array
    {
        return Branch::orderBy('code')->pluck('name', 'id')->all();
    }

    /** Any choice changed: run again (a new dataset starts from its first column and figure). */
    public function updated(string $property): void
    {
        if ($property === 'dataset') {
            $this->resetChoices();
        }
        if (! in_array($property, ['result', 'error', 'report'], true)) {
            $this->drill = $property === 'dataset' ? [] : $this->drill;
            $this->runReport();
        }
    }

    private function resetChoices(): void
    {
        $f = $this->fields();
        $this->dimensions = array_slice(array_keys($f['dims']), 0, 1);
        $this->measures = array_slice(array_keys($f['measures']), 0, 1);
        $this->filterDim = null;
        $this->filterValue = null;
        $this->sort = null;
    }

    public function definition(): array
    {
        return ['dimensions' => array_values($this->dimensions), 'measures' => array_values($this->measures), 'period' => $this->period, 'from' => $this->from, 'to' => $this->to,
            'branch_id' => $this->branch_id ?: null, 'filters' => $this->filterDim && filled($this->filterValue) ? [$this->filterDim => $this->filterValue] : [],
            'sort' => $this->sort, 'direction' => $this->direction];
    }

    public function runReport(): void
    {
        $this->error = null;
        try {
            $this->result = app(ReportEngine::class)->run(Erp::user(), $this->dataset, $this->definition(), $this->drill);
        } catch (DomainException $e) {
            $this->result = null;
            $this->error = $e->getMessage();
        }
    }

    /** Open the rows behind one line of a grouped report. */
    public function drillInto(int $index): void
    {
        $raw = $this->result['raw'][$index] ?? null;
        if ($raw) {
            $this->drill = $raw;
            $this->runReport();
        }
    }

    public function back(): void
    {
        $this->drill = [];
        $this->runReport();
    }

    protected function getHeaderActions(): array
    {
        $saved = $this->savedReport();

        return [
            Action::make('save')->label($saved ? 'Save changes' : 'Save report')->icon(Heroicon::OutlinedBookmark)
                ->visible(fn () => ! $saved || $saved->owner_id === Erp::user()->id)
                ->fillForm(fn () => $saved ? $saved->only(['name', 'shared', 'schedule', 'schedule_day', 'schedule_time', 'format', 'recipients'])
                    : ['schedule' => 'none', 'schedule_time' => '07:00', 'format' => 'pdf', 'recipients' => []])
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    Toggle::make('shared')->label('Others who may see this data can run it too'),
                    Select::make('schedule')->label('Send it automatically')->options(SavedReport::SCHEDULES)->live()->visible(fn () => Erp::canAnywhere('reports.schedule')),
                    Select::make('schedule_day')->label(fn (Get $get) => $get('schedule') === 'weekly' ? 'Weekday' : 'Day of the month')
                        ->options(fn (Get $get) => $get('schedule') === 'weekly' ? [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday']
                            : array_combine(range(1, 28), range(1, 28)))->visible(fn (Get $get) => in_array($get('schedule'), ['weekly', 'monthly'], true)),
                    TextInput::make('schedule_time')->label('At (HH:MM)')->default('07:00')->visible(fn (Get $get) => ($get('schedule') ?? 'none') !== 'none'),
                    Select::make('format')->options(['pdf' => 'PDF', 'xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV'])->visible(fn (Get $get) => ($get('schedule') ?? 'none') !== 'none'),
                    Repeater::make('recipients')->columns(2)->defaultItems(0)->addActionLabel('Add recipient')->visible(fn (Get $get) => ($get('schedule') ?? 'none') !== 'none')->schema([
                        Select::make('type')->options(['email' => 'E-mail (with the file)', 'whatsapp' => 'WhatsApp (a note)', 'user' => 'A person (back-office bell)'])->required()->live(),
                        TextInput::make('to')->required()->visible(fn (Get $get) => $get('type') !== 'user'),
                        Select::make('to')->label('Person')->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->visible(fn (Get $get) => $get('type') === 'user'),
                    ]),
                ])
                ->action(function (array $data) use ($saved) {
                    $r = Erp::run(fn ($actor) => app(ReportService::class)->save($actor, $saved ?? new SavedReport, $data + ['dataset' => $this->dataset, 'definition' => $this->definition()]), 'Report saved');
                    if ($r) {
                        $this->redirect(self::getUrl(['report' => $r->id]));
                    }
                }),
            Action::make('sendNow')->label('Send now')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')->requiresConfirmation()
                ->visible(fn () => $saved && $saved->owner_id === Erp::user()->id && $saved->schedule !== 'none')
                ->action(function () use ($saved) {
                    if (Erp::run(fn () => app(ReportService::class)->send($saved) ?? true)) {
                        Notification::make()->title('Sent to the recipients')->success()->send();
                    }
                }),
            ...$this->exportActions(),
        ];
    }

    protected function exportFilters(): array
    {
        return array_filter(['Period: '.($this->result['period'] ?? ''), $this->branch_id ? 'Branch: '.(Branch::find($this->branch_id)?->name ?? '') : null,
            $this->drill ? 'Detail of: '.implode(', ', $this->drill) : null]);
    }
}
