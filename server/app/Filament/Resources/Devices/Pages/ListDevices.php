<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Support\Erp;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Remote support phase 2: the client's device-group link on the relay, to open on each till, PC and handheld.
            Action::make('remote_agent')->label('Install remote support')->icon(Heroicon::OutlinedComputerDesktop)->color('gray')
                ->visible(fn () => config('remote.relay_url') !== '' && (Erp::user()?->isSupport() || Erp::canAnywhere('remote.control')))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalDescription('Install once on each till, back-office computer and handheld that support may need to see. The device then appears here with View screen / Take control.')
                ->modalContent(fn () => new HtmlString(self::agentSteps((string) app(Settings::class)->get('remote.agent_link', Erp::user()->tenant_id)))),
            CreateAction::make(),
        ];
    }

    private static function agentSteps(string $link): string
    {
        if ($link === '') {
            return '<p>No install link yet: support adds this company’s device group link under System → Settings → Remote access (installation guide, chapter 20).</p>';
        }
        $e = e($link);

        return '<ol style="list-style:decimal;padding-left:1.25rem;line-height:1.7">'
            .'<li>On the device, open this link: <a href="'.$e.'" target="_blank" rel="noopener" style="word-break:break-all;text-decoration:underline">'.$e.'</a></li>'
            .'<li><strong>Windows</strong> (tills, back-office PCs): download the agent and choose <em>Install</em>. It runs in the background and starts with the computer.</li>'
            .'<li><strong>Android</strong> (handhelds, tablets): install <em>MeshAgent</em> from Google Play, then scan the QR code shown on the link page, and allow screen sharing when asked.</li>'
            .'<li>Open the till once on the device, so this page learns its computer name.</li>'
            .'</ol><p style="margin-top:.5rem">Whether the person at the device must accept each connection follows the branch’s <em>Remote connections</em> setting. Every connection is in the audit log and recorded on the relay.</p>';
    }
}
