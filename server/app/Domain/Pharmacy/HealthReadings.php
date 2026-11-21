<?php

namespace App\Domain\Pharmacy;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\HealthReading;
use App\Models\Product;
use App\Models\Sale;
use App\Security\Authorizer;
use Carbon\CarbonInterface;

/**
 * Readings taken as a paid service (owner request 2026-11-06): a blood pressure check, blood sugar, weight, temperature;
 * and (2026-11-21) HB, HIV and typhoid rapid tests and wound dressing. An HIV result never prints on a receipt and is
 * kept out of the audit log (HealthReading::RECEIPT_ONLY_DONE, auditRedacted).
 * The service itself is an ordinary product with no stock, sold at the till; a product marked with a reading kind asks
 * for the reading there. Kept against the customer when one is on the sale, so the patient has a history; a walk-in's
 * reading is on their slip and in the list. The flag follows common screening limits and only prompts the staff to refer
 * the person to a clinic: it is not a diagnosis. Readings are never changed: a mistake is taken again.
 */
final class HealthReadings
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /**
     * normal, low, high, urgent, retest or done: blood pressure in mmHg, sugar in mmol/L (any time of day), temperature
     * in °C, HB in g/dL; a reactive HIV test is referred now, a positive typhoid test to a clinic, an invalid one tested again.
     */
    public static function flag(string $kind, ?int $systolic, ?int $diastolic, ?float $value, ?string $result = null): string
    {
        return match ($kind) {
            'bp' => match (true) {
                $systolic >= 180 || $diastolic >= 120 => 'urgent',
                $systolic >= 140 || $diastolic >= 90 => 'high',
                $systolic < 90 || $diastolic < 60 => 'low',
                default => 'normal',
            },
            'sugar' => match (true) {
                $value >= 16.7 || $value < 3.0 => 'urgent',
                $value >= 11.1 => 'high',
                $value < 3.9 => 'low',
                default => 'normal',
            },
            'temperature' => match (true) {
                $value >= 39.5 || $value < 35.0 => 'urgent',
                $value >= 38.0 => 'high',
                $value < 36.0 => 'low',
                default => 'normal',
            },
            'hb' => match (true) {
                $value < 7.0 => 'urgent',
                $value < 12.0 => 'low',
                $value >= 18.0 => 'high',
                default => 'normal',
            },
            'hiv' => ['reactive' => 'urgent', 'invalid' => 'retest'][$result] ?? 'normal',
            'typhoid' => ['positive' => 'high', 'invalid' => 'retest'][$result] ?? 'normal',
            'wound' => 'done',
            default => 'normal',
        };
    }

    /** @param array{kind: string, branch_id: string, customer_id?: ?string, systolic?: mixed, diastolic?: mixed, pulse?: mixed, value?: mixed, note?: ?string, sale_id?: ?string, product_id?: ?string, taken_at?: mixed} $d */
    private function make(Actor $actor, array $d, CarbonInterface $at): HealthReading
    {
        $kind = (string) ($d['kind'] ?? '');
        if (! array_key_exists($kind, HealthReading::KINDS)) {
            throw new DomainException('Choose what was measured.');
        }
        $num = fn (string $k) => isset($d[$k]) && $d[$k] !== '' ? (int) $d[$k] : null;
        [$sys, $dia, $pulse] = [$num('systolic'), $num('diastolic'), $num('pulse')];
        $value = isset($d['value']) && $d['value'] !== '' ? (float) $d['value'] : null;
        if ($kind === 'bp' && (! $sys || ! $dia || $sys < 50 || $sys > 300 || $dia < 30 || $dia > 200 || $dia >= $sys)) {
            throw new DomainException('Enter the two blood pressure numbers, e.g. 120 over 80.');
        }
        $result = isset($d['result']) && $d['result'] !== '' ? (string) $d['result'] : null;
        $note = trim((string) ($d['note'] ?? ''));
        if (isset(HealthReading::RESULTS[$kind])) {
            if (! array_key_exists((string) $result, HealthReading::RESULTS[$kind])) {
                throw new DomainException('Choose the test result.');
            }
            $value = null;
        } else {
            $result = null;
        }
        if ($kind === 'wound' && mb_strlen($note) < 3) {
            throw new DomainException('Write what was done, e.g. left leg cleaned and dressed.');
        }
        if ($kind === 'hb' && ($value === null || $value < 1 || $value > 25)) {
            throw new DomainException('Enter the HB in g/dL, e.g. 12.5.');
        }
        if (! in_array($kind, ['bp', 'hiv', 'typhoid', 'wound'], true) && ($value === null || $value <= 0 || $value > 1000)) {
            throw new DomainException('Enter the reading.');
        }
        $r = (new HealthReading)->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $d['branch_id'], 'customer_id' => ($d['customer_id'] ?? null) ?: null, 'sale_id' => $d['sale_id'] ?? null, 'product_id' => $d['product_id'] ?? null,
            'kind' => $kind, 'systolic' => $sys, 'diastolic' => $dia, 'pulse' => $pulse, 'value' => $value, 'result' => $result, 'flag' => self::flag($kind, $sys, $dia, $value, $result),
            'note' => $note !== '' ? mb_substr($note, 0, 255) : null, 'taken_by' => $actor->id, 'taken_by_name' => $actor->name, 'taken_at' => $at]);

        return $this->recorder->save($actor, $r, 'health_reading.taken', HealthReading::KINDS[$kind], $d['branch_id']);
    }

    /** Taken in the back office, away from a sale. */
    public function record(Actor $actor, array $d): HealthReading
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'pharmacy.patients')) {
            throw new AccessDenied;
        }

        return $this->make($actor, $d, now());
    }

    /**
     * Readings entered at the till with the sale. A sale is never refused for one: it may have left the till hours ago
     * when it arrives, so a reading that cannot be kept is dropped and the sale stands.
     *
     * @param  list<array<string, mixed>>  $lines  the sale's lines as sent
     */
    public function fromSale(Actor $actor, Sale $sale, array $lines): void
    {
        foreach ($lines as $l) {
            if (empty($l['reading']) || ! is_array($l['reading'])) {
                continue;
            }
            $kind = Product::whereKey($l['product_id'] ?? null)->value('reading_kind');
            if (! $kind) {
                continue;
            }
            try {
                $this->make($actor, ['kind' => $kind, 'branch_id' => $sale->branch_id, 'customer_id' => $sale->customer_id, 'sale_id' => $sale->id, 'product_id' => $l['product_id']] + $l['reading'], $sale->occurred_at);
            } catch (DomainException) {
                // the note on the receipt line still shows what was entered
            }
        }
    }
}
