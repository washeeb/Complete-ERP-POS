<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The company's mobile money payment provider on this server. Credentials are encrypted with this server's key and never synced. */
class MomoGateway extends Model
{
    use BelongsToTenant, HasUuids;

    public const PROVIDERS = ['paystack' => 'Paystack', 'hubtel' => 'Hubtel', 'custom' => 'Other provider (custom gateway)'];

    /** provider => [field => label] */
    public const FIELDS = [
        'paystack' => ['secret_key' => 'Secret key (sk_live_… or sk_test_…)'],
        'hubtel' => ['client_id' => 'API ID (client ID)', 'client_secret' => 'API key (client secret)', 'pos_sales_id' => 'POS Sales ID (merchant account number)'],
        'custom' => ['url' => 'Gateway address (https://…)', 'secret' => 'Shared secret'],
    ];

    protected $guarded = [];

    protected $hidden = ['credentials', 'webhook_token'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean'];
    }
}
