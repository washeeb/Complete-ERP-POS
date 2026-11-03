<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\Erp;
use App\Filament\Support\ServiceUi;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    public function getSubheading(): ?string
    {
        return ServiceUi::can('helpdesk.view')
            ? 'Requests and complaints, with the time left to answer and to fix them. Open one for a customer at the counter or on the phone with “New ticket”.'
            : 'Ask for help (a broken printer, a stock problem…) and follow what happens to it.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('channels')->label('Where tickets come from')->icon(Heroicon::OutlinedInformationCircle)->color('gray')
                ->visible(fn () => ServiceUi::can('helpdesk.manage'))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn () => new HtmlString(self::channels())),
            CreateAction::make()->label(ServiceUi::can('helpdesk.work') ? 'New ticket' : 'Ask for help'),
        ];
    }

    /** The web form's link and the e-mail forwarding address, as they are for this company. */
    private static function channels(): string
    {
        $tenant = Erp::user()->tenant;
        $s = app(Settings::class);
        $form = $s->get('service.web_form', $tenant->id) ? e(route('service.help', ['company' => $tenant->slug])) : 'off — switch it on under System → Settings → Service desk and maintenance';
        $key = (string) $s->get('service.email_key', $tenant->id);
        $mail = strlen($key) >= 24 ? e(route('service.email', ['key' => $key])) : 'off — set a key under System → Settings → Service desk and maintenance';

        return '<div class="space-y-3 text-sm">'
            .'<p><b>WhatsApp</b>: messages to your WhatsApp Business number arrive under Online → WhatsApp orders; turn one into a ticket there. A customer with a ticket open on WhatsApp has their next messages added to it.</p>'
            .'<p><b>Web form</b>: '.$form.'</p>'
            .'<p><b>E-mail</b>: have your mail provider forward incoming mail (inbound parse / route) to '.$mail.'. Keep this address private.</p>'
            .'<p><b>Staff</b>: “New ticket” here, for a customer at the counter or on the phone; staff ask for help for themselves the same way.</p></div>';
    }
}
