<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'module', 'label', 'min_level', 'is_technical'];

    protected function casts(): array
    {
        return ['is_technical' => 'boolean', 'min_level' => 'integer'];
    }
}
