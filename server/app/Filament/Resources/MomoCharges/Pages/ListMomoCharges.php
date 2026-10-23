<?php

namespace App\Filament\Resources\MomoCharges\Pages;

use App\Domain\Payments\MomoCollections;
use App\Filament\Resources\MomoCharges\MomoChargeResource;
use App\Filament\Support\Erp;
use App\Models\MomoGateway;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ListMomoCharges extends ListRecords
{
    protected static string $resource = MomoChargeResource::class;

    public function getSubheading(): ?string
    {
        $g = MomoGateway::first();

        return $g?->is_active
            ? 'Payment requests go through '.MomoGateway::PROVIDERS[$g->provider].'. How the till takes mobile money: System → Settings → Mobile money.'
            : 'Payment requests are off: the cashier types the transaction ID. Set up a provider with “Payment provider”.';
    }

    protected function getHeaderActions(): array
    {
        $saved = MomoGateway::first();
        $fields = [];
        foreach (MomoGateway::FIELDS as $provider => $list) {
            foreach ($list as $key => $label) {
                $secret = ! in_array($key, ['pos_sales_id', 'client_id', 'url'], true);
                $fields[] = TextInput::make("credentials.{$provider}.{$key}")->label($label)->password($secret)->revealable($secret)->autocomplete($secret ? 'new-password' : null)
                    ->placeholder($saved?->provider === $provider && ! empty(($saved->credentials ?? [])[$key]) ? 'Saved — leave empty to keep' : null)
                    ->visible(fn (Get $get) => $get('provider') === $provider);
            }
        }

        return [
            Action::make('gateway')->label('Payment provider')->icon(Heroicon::OutlinedKey)->visible(fn () => Erp::can('payments.momo.manage'))
                ->modalDescription('The company’s own merchant account with a payment provider that collects from MTN MoMo, Telecel Cash and AT Money. Money goes to that account and is settled to the company’s bank or wallet by the provider.')
                ->fillForm(fn () => ['provider' => $saved?->provider ?? 'paystack', 'is_active' => $saved?->is_active ?? false])
                ->schema([
                    Select::make('provider')->label('Provider')->options(MomoGateway::PROVIDERS)->required()->live(),
                    ...$fields,
                    Toggle::make('is_active')->label('Send payment requests through this provider'),
                    Text::make(fn () => $saved ? 'Callback address for the provider’s dashboard: '.route('momo.webhook', ['token' => $saved->webhook_token]) : 'The callback address appears here after the first save.'),
                ])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(MomoCollections::class)->saveGateway($actor, ['provider' => $data['provider'],
                    'credentials' => $data['credentials'][$data['provider']] ?? [], 'is_active' => $data['is_active'] ?? false]), 'Payment provider saved')),
        ];
    }
}
