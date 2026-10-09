<?php

namespace App\Filament\Resources\SupportReports\Pages;

use App\Domain\Launch\SupportReports;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\ListRecords;

class ListSupportReports extends ListRecords
{
    protected static string $resource = SupportReportResource::class;

    public function getSubheading(): ?string
    {
        $to = SupportReports::address(Erp::user()->tenant_id);

        return $to ? "New reports are e-mailed to {$to}." : 'No support e-mail address yet: set it in System → Go-live → Plan (or ERP_SUPPORT_EMAIL).';
    }
}
