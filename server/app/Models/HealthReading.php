<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A blood pressure, sugar, weight, temperature, HB, HIV or typhoid test, or wound dressing at the pharmacy. See App\Domain\Pharmacy\HealthReadings. */
class HealthReading extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = ['bp' => 'Blood pressure', 'sugar' => 'Blood sugar', 'weight' => 'Weight', 'temperature' => 'Temperature',
        'hb' => 'HB (haemoglobin)', 'hiv' => 'HIV test', 'typhoid' => 'Typhoid test', 'wound' => 'Wound dressing'];

    /** Tests read as a result, not a number (rapid tests). */
    public const RESULTS = [
        'hiv' => ['non_reactive' => 'Non-reactive', 'reactive' => 'Reactive', 'invalid' => 'Invalid (test again)'],
        'typhoid' => ['negative' => 'Negative', 'positive' => 'Positive', 'invalid' => 'Invalid (test again)'],
    ];

    /** An HIV result is told to the person, never printed: the receipt line says only that the test was done. */
    public const RECEIPT_ONLY_DONE = ['hiv' => 'HIV test done'];

    public const FLAGS = ['normal' => 'Normal', 'low' => 'Low', 'high' => 'High', 'urgent' => 'Refer now', 'retest' => 'Test again', 'done' => 'Done'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'value' => 'decimal:2'];
    }

    /**
     * Fields kept out of the audit log: an HIV test's result (and the flag and note, which would reveal it) is seen only
     * by people who may see patients, on Health checks; the audit log says that a test was recorded, not its result.
     *
     * @return list<string>
     */
    public function auditRedacted(): array
    {
        return $this->kind === 'hiv' ? ['result', 'flag', 'note'] : [];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** "128/82, pulse 74", "5.4 mmol/L". */
    public function summary(): string
    {
        return match ($this->kind) {
            'bp' => "{$this->systolic}/{$this->diastolic} mmHg".($this->pulse ? ", pulse {$this->pulse}" : ''),
            'sugar' => rtrim(rtrim((string) $this->value, '0'), '.').' mmol/L',
            'weight' => rtrim(rtrim((string) $this->value, '0'), '.').' kg',
            'temperature' => rtrim(rtrim((string) $this->value, '0'), '.').' °C',
            'hb' => rtrim(rtrim((string) $this->value, '0'), '.').' g/dL',
            'hiv', 'typhoid' => self::RESULTS[$this->kind][$this->result] ?? (string) $this->result,
            'wound' => (string) ($this->note ?? 'Dressed'),
            default => (string) $this->value,
        };
    }
}
