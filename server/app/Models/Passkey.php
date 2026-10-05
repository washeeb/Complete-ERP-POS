<?php

namespace App\Models;

use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use Spatie\LaravelPasskeys\Models\Passkey as BasePasskey;

/** Adding or removing a passkey is a security change: it is audited in the same transaction. */
class Passkey extends BasePasskey
{
    public function save(array $options = []): bool
    {
        $isNew = ! $this->exists;
        if (! $isNew) {
            return parent::save($options); // last_used_at updates on sign-in are not security changes
        }

        return app(Recorder::class)->transaction(function () use ($options) {
            $saved = parent::save($options);
            $this->audit('auth.passkey_added', ['name' => [null, $this->name]]);

            return $saved;
        });
    }

    public function delete(): ?bool
    {
        return app(Recorder::class)->transaction(function () {
            $this->audit('auth.passkey_removed', ['name' => [$this->name, null]]);

            return parent::delete();
        });
    }

    private function audit(string $action, array $changes): void
    {
        $owner = User::find($this->authenticatable_id);
        if ($owner) {
            app(Recorder::class)->audit->record(Actor::user($owner, 'web'), $action, $owner, $changes);
        }
    }
}
