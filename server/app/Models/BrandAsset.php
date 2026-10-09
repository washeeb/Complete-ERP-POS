<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A company's logo, dark-background logo or square icon (Company → Branding). */
class BrandAsset extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = [
        'logo' => 'Logo (for light backgrounds)',
        'logo_dark' => 'Logo for dark backgrounds (optional)',
        'icon' => 'Square icon (browser tab, app icon)',
    ];

    protected $guarded = [];

    /** Large: never loaded unless asked for. */
    protected $hidden = ['data'];

    public function extension(): string
    {
        return ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'][$this->mime] ?? 'bin';
    }

    public function dataUri(): string
    {
        return "data:{$this->mime};base64,{$this->data}";
    }
}
