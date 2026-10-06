<?php

namespace App\Models;

use App\Domain\Sync\Replication;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncPeer extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const MODES = ['auto' => 'Automatic', 'manual' => 'Manual (preview first)', 'off' => 'Off'];

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'groups' => 'array',
            'is_active' => 'boolean',
            'last_success_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'last_pushed_seq' => 'integer',
            'last_pulled_seq' => 'integer',
        ];
    }

    public function inbox(): HasMany
    {
        return $this->hasMany(SyncInboxEvent::class, 'peer_id');
    }

    public function modeFor(string $group): string
    {
        return $this->groups[$group] ?? 'off';
    }

    /**
     * Groups this node sends to the peer / receives from it, by node roles. Branch → HQ: sales,
     * reviews, stock. HQ → branch: master data and HQ's stock movements.
     *
     * @return list<string>
     */
    public function groupsWeSend(): array
    {
        $weAreHq = config('erp.node.role') !== 'branch';

        return array_values(array_filter(array_keys(Replication::GROUPS), function ($g) use ($weAreHq) {
            $dir = Replication::GROUPS[$g]['direction'];

            return $dir === 'both' || ($weAreHq ? $dir === 'down' : $dir === 'up');
        }));
    }

    /** @return list<string> */
    public function groupsWeReceive(): array
    {
        $weAreHq = config('erp.node.role') !== 'branch';

        return array_values(array_filter(array_keys(Replication::GROUPS), function ($g) use ($weAreHq) {
            $dir = Replication::GROUPS[$g]['direction'];

            return $dir === 'both' || ($weAreHq ? $dir === 'up' : $dir === 'down');
        }));
    }
}
