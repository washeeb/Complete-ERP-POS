<?php

namespace App\Filament\Pages;

use App\Domain\Pricing\PricingRules;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\PriceSuggestion;
use App\Security\Authorizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Pricing & tax → Prices to review: prices the pricing rules worked out that wait for a manager — drops by default,
 * or every change when a rule says so. Approve (one, or many at once) or dismiss.
 */
class PricesToReview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & tax';

    protected static ?string $navigationLabel = 'Prices to review';

    protected static ?string $title = 'Prices to review';

    protected static ?string $slug = 'prices-to-review';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.prices-to-review';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('pricing.manage');
    }

    /** Suggestions for products of the industries this person prices (all for company-wide ones). */
    private static function mine()
    {
        $ids = ($u = Erp::user()) ? app(Authorizer::class)->industryIds($u, 'pricing.manage') : [];

        return PriceSuggestion::query()->where('status', 'pending')->when($ids !== null, fn ($q) => $q->whereHas('product', fn ($p) => $p->whereIn('industry_id', $ids)));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = self::mine()->count();

        return $n ? (string) $n : null;
    }

    public function getSubheading(): ?string
    {
        return 'New prices from the pricing rules, waiting for your approval. Approve them (several at once with the boxes) or dismiss them to keep the current price.';
    }

    public function table(Table $table): Table
    {
        $rules = app(PricingRules::class);

        return $table
            ->query(self::mine()->with(['product', 'productUom.uom', 'priceList']))
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable()->description(fn (PriceSuggestion $s) => $s->product?->sku),
                TextColumn::make('productUom.uom.code')->label('Unit'),
                TextColumn::make('priceList.name')->label('Price list'),
                TextColumn::make('cost_minor')->label('Cost')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('current_minor')->label('Now')->alignEnd()->formatStateUsing(fn ($state) => $state === null ? '—' : FinanceUi::money((int) $state)),
                TextColumn::make('suggested_minor')->label('New price')->alignEnd()->weight('bold')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state))
                    ->description(fn (PriceSuggestion $s) => $s->current_minor ? sprintf('%+.1f %%', ($s->suggested_minor - $s->current_minor) * 100 / max(1, $s->current_minor)) : 'new'),
                TextColumn::make('reason')->label('Why')->wrap()->color('gray'),
                TextColumn::make('updated_at')->label('Since')->since(),
            ])
            ->recordActions([
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->action(fn (PriceSuggestion $record) => $this->done(Erp::run(fn ($actor) => $rules->approve($actor, [$record])), 'approved')),
                Action::make('dismiss')->label('Keep current')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->action(fn (PriceSuggestion $record) => $this->done(Erp::run(fn ($actor) => $rules->dismiss($actor, [$record])), 'kept as they are')),
            ])
            ->toolbarActions([
                BulkAction::make('approveMany')->label('Approve selected')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->action(fn (Collection $records) => $this->done(Erp::run(fn ($actor) => $rules->approve($actor, $records)), 'approved')),
                BulkAction::make('dismissMany')->label('Keep current prices')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->action(fn (Collection $records) => $this->done(Erp::run(fn ($actor) => $rules->dismiss($actor, $records)), 'kept as they are')),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No prices waiting')
            ->emptyStateDescription('New prices from the pricing rules appear here when a rule asks for approval.');
    }

    private function done(int $n, string $what): void
    {
        Notification::make()->success()->title("{$n} price(s) {$what}")->send();
    }
}
