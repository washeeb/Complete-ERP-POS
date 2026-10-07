<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One choice in a modifier group; a priced extra is charged as its product. */
class ModifierOption extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];
}
