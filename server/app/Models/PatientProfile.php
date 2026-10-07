<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Model;

/** Patient details for a customer (Pharmacy): allergies, NHIS membership. */
class PatientProfile extends Model
{
    use BelongsToTenant, HasOriginNode;

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allergies' => 'array', 'date_of_birth' => 'date', 'nhis_expires_on' => 'date'];
    }
}
