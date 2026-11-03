<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Resources\MaintenanceAssets\MaintenanceAssetResource;
use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\ServiceUi;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard: service desk and maintenance — open and late tickets, customers' rating, equipment down, work due. */
class ServicePanel extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Service desk and maintenance';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $stats = [];
        if (ServiceUi::can('helpdesk.view')) {
            $tickets = fn () => ServiceUi::tickets(Ticket::query());
            $late = $tickets()->whereIn('status', ['new', 'open'])
                ->where(fn ($w) => $w->where(fn ($a) => $a->whereNull('first_response_at')->where('respond_by', '<', now()))->orWhere('resolve_by', '<', now()))->count();
            $rating = $tickets()->whereNotNull('rating')->where('resolved_at', '>=', now()->subDays(30))->avg('rating');
            $stats[] = Stat::make('Open tickets', (string) $tickets()->whereIn('status', ['new', 'open', 'waiting'])->count())
                ->description($late ? "{$late} late" : 'None late')->color($late ? 'danger' : 'success')->url(TicketResource::getUrl('index'));
            $stats[] = Stat::make('Customers’ rating (30 days)', $rating ? number_format((float) $rating, 1).' / 5' : '—');
        }
        if (ServiceUi::can('maintenance.view')) {
            $down = ServiceUi::maintenance(MaintenanceAsset::query())->where('owner', 'own')->where('status', 'down')->count();
            $due = ServiceUi::maintenance(MaintenanceOrder::query())->whereIn('status', ['open', 'working', 'waiting'])->count();
            $stats[] = Stat::make('Equipment not working', (string) $down)->color($down ? 'danger' : 'success')->url(MaintenanceAssetResource::getUrl('index'));
            $stats[] = Stat::make('Work orders open', (string) $due)->url(MaintenanceOrderResource::getUrl('index'));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        return ServiceUi::can('helpdesk.view') || ServiceUi::can('maintenance.view');
    }
}
