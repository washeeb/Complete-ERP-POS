<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LegalEntity extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['name', 'registration_no', 'tin', 'vat_no', 'currency_code'];
}
