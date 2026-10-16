<?php

namespace App\Models;

use App\Domain\Modules\ModuleService;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use App\Support\ClientInfo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model
{
    use BelongsToTenant, HasApiTokens, HasOriginNode, HasUuids;

    public const KINDS = ['pos' => 'POS till', 'handheld' => 'Handheld scanner', 'kiosk' => 'Self-checkout kiosk', 'kds' => 'Kitchen display'];

    /**
     * The kinds that can be registered at a branch: a kitchen display only where the Restaurant module runs; the
     * self-checkout kiosk not at all until it is built.
     *
     * @return array<string, string>
     */
    public static function kindsFor(?string $branchId): array
    {
        $kinds = ['pos' => self::KINDS['pos'], 'handheld' => self::KINDS['handheld']];
        if ($branchId && app(ModuleService::class)->isEnabled($branchId, 'restaurant')) {
            $kinds['kds'] = self::KINDS['kds'];
        }

        return $kinds;
    }

    protected $fillable = ['branch_id', 'till_id', 'name', 'kind'];

    protected $hidden = ['enrollment_code_hash', 'enrollment_lookup', 'public_key'];

    protected function casts(): array
    {
        return [
            'enrollment_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_client' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Mark the device as seen now, from this request's computer and address. */
    public function seen(): void
    {
        $this->forceFill(['last_seen_at' => now(), 'last_ip' => request()->ip(), 'last_client' => ClientInfo::current()])->saveQuietly();
    }
}
