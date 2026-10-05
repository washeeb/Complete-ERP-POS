<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Domain\Support\AuditVerifier;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;

    protected static ?string $title = 'Audit log';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')->label('Verify chain')->icon(Heroicon::OutlinedShieldCheck)
                ->visible(fn () => Erp::can('audit.verify'))
                ->action(function () {
                    $r = app(AuditVerifier::class)->verify(Erp::user()->tenant_id);
                    $r['ok']
                        ? Notification::make()->success()->title("Audit chain intact ({$r['checked']} rows)")->send()
                        : Notification::make()->danger()->persistent()->title("Audit chain broken at row {$r['first_bad_seq']}")
                            ->body($r['problem'].' Treat this as a security incident: see docs/security.md.')->send();
                }),
        ];
    }
}
