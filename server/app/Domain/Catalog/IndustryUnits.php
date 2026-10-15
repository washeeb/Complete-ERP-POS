<?php

namespace App\Domain\Catalog;

use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Models\Uom;

/**
 * Units an industry sells in, added when its module is switched on (and, for companies that already run it, by a
 * migration). Only missing ones are added: a unit with the same code or name is left as the company has it.
 */
final class IndustryUnits
{
    /** module => [code, name, decimals] */
    public const UNITS = [
        'pharmacy' => [
            ['TAB', 'Tablet', 0], ['STRIP', 'Strip', 0], ['PK', 'Pack', 0], ['AMP', 'Ampoule', 0], ['VIAL', 'Vial', 0],
            ['TUBE', 'Tube', 0], ['BTL', 'Bottle', 0], ['PESS', 'Pessary', 0], ['SUPP', 'Suppository', 0], ['CONT', 'Container', 0],
        ],
    ];

    public function __construct(private readonly Recorder $recorder) {}

    /** @return int how many units were added */
    public function ensure(string $tenantId, string $moduleKey): int
    {
        $units = self::UNITS[$moduleKey] ?? [];
        if (! $units) {
            return 0;
        }
        $have = Uom::withoutGlobalScopes()->where('tenant_id', $tenantId)->get(['code', 'name']);
        $codes = $have->pluck('code')->map(fn ($c) => strtoupper($c))->all();
        $names = $have->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $actor = Actor::system($tenantId, 'Starter data');
        $added = 0;
        foreach ($units as [$code, $name, $decimals]) {
            if (in_array($code, $codes, true) || in_array(mb_strtolower($name), $names, true)) {
                continue;
            }
            $uom = new Uom(['code' => $code, 'name' => $name, 'decimals' => $decimals]);
            $uom->tenant_id = $tenantId;
            $this->recorder->save($actor, $uom, 'uom.created', "Units for the {$moduleKey} module");
            $added++;
        }

        return $added;
    }
}
