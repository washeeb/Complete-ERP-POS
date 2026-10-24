<?php

namespace App\Support;

/**
 * Values worked out once per request (or queue job) instead of once per row of a list (speed sweep 2026-10-24): the
 * company's currency, an account's purposes… Request-scoped, so nothing leaks into the next request; keyed by company.
 */
final class RequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function remember(string $key, callable $make): mixed
    {
        $key = app(TenantContext::class)->id().'|'.$key;

        return array_key_exists($key, $this->values) ? $this->values[$key] : ($this->values[$key] = $make());
    }

    public function forget(): void
    {
        $this->values = [];
    }

    public static function get(string $key, callable $make): mixed
    {
        return app(self::class)->remember($key, $make);
    }
}
