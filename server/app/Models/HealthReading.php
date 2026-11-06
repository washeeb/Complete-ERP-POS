<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A blood pressure, sugar, weight or temperature taken at the pharmacy. See App\Domain\Pharmacy\HealthReadings. */
class HealthReading extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = ['bp' => 'Blood pressure', 'sugar' => 'Blood sugar', 'weight' => 'Weight', 'temperature' => 'Temperature'];

    public const FLAGS = ['normal' => 'Normal', 'low' => 'Low', 'high' => 'High', 'urgent' => 'Refer now'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'value' => 'decimal:2'];
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
            default => (string) $this->value,
        };
    }
}
