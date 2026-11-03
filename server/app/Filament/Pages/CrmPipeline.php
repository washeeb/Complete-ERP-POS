<?php

namespace App\Filament\Pages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Support\CrmUi;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\CrmDeal;
use App\Models\CrmStage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * CRM → Pipeline: the open deals as cards in a column per stage, with each column's total and weighted value (value ×
 * the stage's chance). Drag a card to another stage, or onto Won / Lost. "Mine" shows only the person's own deals.
 */
class CrmPipeline extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?string $title = 'Pipeline';

    protected static ?string $slug = 'crm-pipeline';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.crm-pipeline';

    public bool $mine = false;

    public static function canAccess(): bool
    {
        return CrmUi::canView();
    }

    public function getSubheading(): ?string
    {
        return 'Drag a deal to its new stage, or onto Won or Lost. A deal is also won by itself when its quotation is confirmed.';
    }

    /** @return list<array{stage: CrmStage, deals: Collection, total: int, weighted: int}> */
    public function columns(): array
    {
        $deals = CrmUi::scope(CrmDeal::query())->where('status', 'open')->with(['customer', 'lead', 'owner', 'order'])
            ->when($this->mine, fn ($q) => $q->where('owner_id', Erp::user()?->id))
            ->orderByRaw('expected_close_on IS NULL, expected_close_on')->orderByDesc('value_minor')->get()->groupBy('stage_id');
        $out = [];
        foreach (app(Crm::class)->openStages() as $stage) {
            $list = $deals->get($stage->id, collect());
            $total = (int) $list->sum('value_minor');
            $out[] = ['stage' => $stage, 'deals' => $list, 'total' => $total, 'weighted' => intdiv($total * $stage->probability, 100)];
        }

        return $out;
    }

    /** @return array{won: int, won_value: int, lost: int} closed this month */
    public function month(): array
    {
        $q = fn () => CrmUi::scope(CrmDeal::query())->where('closed_at', '>=', now()->startOfMonth())->when($this->mine, fn ($q) => $q->where('owner_id', Erp::user()?->id));

        return ['won' => $q()->where('status', 'won')->count(), 'won_value' => (int) $q()->where('status', 'won')->sum('value_minor'), 'lost' => $q()->where('status', 'lost')->count()];
    }

    public function money(int $minor): string
    {
        return FinanceUi::money($minor);
    }

    public function editUrl(CrmDeal $deal): string
    {
        return CrmDealResource::getUrl('edit', ['record' => $deal]);
    }

    public function canMove(): bool
    {
        return CrmUi::canManage();
    }

    /** A card dropped on another stage in progress, or on Won. */
    public function move(string $dealId, string $to): void
    {
        $deal = CrmUi::scope(CrmDeal::query())->find($dealId);
        if (! $deal) {
            return;
        }
        $crm = app(Crm::class);
        try {
            Erp::run(fn ($actor) => $to === 'won' ? $crm->winDeal($actor, $deal, 'dragged to Won')
                : $crm->moveDeal($actor, $deal, CrmStage::findOrFail($to)), $to === 'won' ? "{$deal->number} won" : null);
        } catch (Halt) {
            // the reason was shown; the board redraws as it was
        }
    }

    /** A card dropped on Lost: asks why. */
    public function loseAction(): Action
    {
        return Action::make('lose')->label('Why was it lost?')->modalHeading(fn (array $arguments) => 'Lost: '.CrmDeal::find($arguments['deal'] ?? null)?->title)
            ->schema([TextInput::make('reason')->label('Why')->required()->maxLength(255)->placeholder('e.g. Price, went to a competitor, no longer needed')])
            ->action(function (array $data, array $arguments) {
                $deal = CrmUi::scope(CrmDeal::query())->find($arguments['deal'] ?? null);
                if ($deal) {
                    Erp::run(fn ($actor) => app(Crm::class)->loseDeal($actor, $deal, $data['reason']), "{$deal->number} lost");
                }
            });
    }

    public function toggleMine(): void
    {
        $this->mine = ! $this->mine;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mine')->label(fn () => $this->mine ? 'Everyone’s deals' : 'My deals only')->icon(Heroicon::OutlinedUser)->color('gray')->action(fn () => $this->toggleMine()),
            Action::make('new')->label('New deal')->icon(Heroicon::OutlinedPlus)->url(fn () => CrmDealResource::getUrl('create'))->visible(fn () => CrmUi::canManage()),
        ];
    }
}
