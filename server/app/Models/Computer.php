<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A computer (browser) that used the back office, recognised by its lasting ID and named by an administrator. */
class Computer extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'last_client' => 'array'];
    }

    /** "Front desk PC", or "Unnamed computer 3F2A" until someone names it. */
    public function label(): string
    {
        return $this->name ?: 'Unnamed computer '.strtoupper(substr(str_replace('-', '', $this->computer_key), -4));
    }

    /** @return array<string, string> computer_key => label, for the audit log (one query per page) */
    public static function labels(): array
    {
        static $labels = null;

        return $labels ??= self::query()->get(['name', 'computer_key'])->mapWithKeys(fn (self $c) => [$c->computer_key => $c->label()])->all();
    }
}
