<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['sku', 'name', 'description', 'category_id', 'brand_id', 'base_uom_id', 'tax_group_id', 'track_stock', 'is_active', 'track_batches', 'plu', 'drug_schedule', 'generic_name', 'strength', 'dosage_form', 'fda_reg_no', 'active_ingredients', 'station', 'modifier_group_ids', 'track_serials', 'warranty_months', 'style_id', 'size', 'colour', 'consignor_id', 'consignment_bp', 'service_minutes', 'commission_bp', 'pack', 'licence_keys'];

    protected function casts(): array
    {
        return ['track_stock' => 'boolean', 'is_active' => 'boolean', 'track_batches' => 'boolean', 'active_ingredients' => 'array', 'modifier_group_ids' => 'array', 'track_serials' => 'boolean', 'warranty_months' => 'integer', 'consignment_bp' => 'integer', 'service_minutes' => 'integer', 'commission_bp' => 'integer', 'pack' => 'array', 'licence_keys' => 'boolean'];
    }

    public const SCHEDULES = ['otc' => 'Over the counter', 'pom' => 'Prescription only (POM)', 'controlled' => 'Controlled drug'];

    public function needsPrescription(): bool
    {
        return in_array($this->drug_schedule, ['pom', 'controlled'], true);
    }

    public function recipe(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'base_uom_id');
    }

    public function taxGroup(): BelongsTo
    {
        return $this->belongsTo(TaxGroup::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUom::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}
