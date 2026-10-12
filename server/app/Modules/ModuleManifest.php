<?php

namespace App\Modules;

final class ModuleManifest
{
    /**
     * @param  list<string>  $requires
     * @param  list<array{key: string, label: string, min_level?: int, technical?: bool}>  $permissions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $version,
        public readonly string $kind,      // core | department | industry
        public readonly string $stage,     // available | planned
        public readonly ?int $phase,       // build phase that releases it (planned modules)
        public readonly string $description,
        public readonly array $requires,
        public readonly array $permissions,
        public readonly bool $addon = false, // an industry module that sits alongside a branch's industry (e.g. Sales & Distribution)
    ) {}

    public static function fromArray(array $m): self
    {
        return new self(
            key: $m['key'],
            name: $m['name'],
            version: $m['version'] ?? '0.0.0',
            kind: $m['kind'],
            stage: $m['stage'] ?? 'planned',
            phase: $m['phase'] ?? null,
            description: $m['description'] ?? '',
            requires: $m['requires'] ?? [],
            permissions: $m['permissions'] ?? [],
            addon: (bool) ($m['addon'] ?? false),
        );
    }

    /** An industry module that makes a branch a branch of that industry (one per branch). */
    public function definesIndustry(): bool
    {
        return $this->kind === 'industry' && ! $this->addon;
    }

    public function isAvailable(): bool
    {
        return $this->stage === 'available';
    }

    public function isCore(): bool
    {
        return $this->kind === 'core';
    }

    public function statusLabel(): string
    {
        return $this->isAvailable() ? 'Available' : 'Planned · arrives in Phase '.$this->phase;
    }
}
