<?php

namespace App\Modules;

use RuntimeException;

/** Reads modules/<Name>/module.json. The files are the source of truth for modules and their permissions. */
final class ModuleRegistry
{
    /** @var array<string, ModuleManifest>|null */
    private ?array $modules = null;

    public function __construct(private readonly string $path) {}

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }
        $this->modules = [];
        foreach (glob($this->path.'/*/module.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $manifest = ModuleManifest::fromArray($data);
            if (isset($this->modules[$manifest->key])) {
                throw new RuntimeException("Duplicate module key [{$manifest->key}] in {$file}");
            }
            $this->modules[$manifest->key] = $manifest;
        }
        foreach ($this->modules as $m) {
            foreach ($m->requires as $dep) {
                if (! isset($this->modules[$dep])) {
                    throw new RuntimeException("Module [{$m->key}] requires unknown module [{$dep}]");
                }
            }
        }
        uasort($this->modules, fn ($a, $b) => [$a->kind !== 'core', $a->name] <=> [$b->kind !== 'core', $b->name]);

        return $this->modules;
    }

    public function get(string $key): ModuleManifest
    {
        return $this->all()[$key] ?? throw new RuntimeException("Unknown module [{$key}]");
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /** @return array<string, ModuleManifest> */
    public function selectable(): array
    {
        return array_filter($this->all(), fn ($m) => ! $m->isCore());
    }

    /**
     * Permissions only come from released modules — a planned module never seeds
     * permissions over screens that don't exist yet.
     *
     * @return list<array{key: string, module: string, label: string, min_level: int, is_technical: bool}>
     */
    public function permissions(): array
    {
        $out = [];
        foreach ($this->all() as $m) {
            if (! $m->isAvailable()) {
                continue;
            }
            foreach ($m->permissions as $p) {
                $out[] = [
                    'key' => $p['key'],
                    'module' => $m->key,
                    'label' => $p['label'],
                    'min_level' => $p['min_level'] ?? 1,
                    'is_technical' => $p['technical'] ?? false,
                ];
            }
        }

        return $out;
    }
}
