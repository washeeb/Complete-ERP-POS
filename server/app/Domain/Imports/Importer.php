<?php

namespace App\Domain\Imports;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * One kind of CSV import. Each row is applied through the same domain service a person would use on screen, so the
 * same checks, permissions, audit trail and sync apply. A "check" runs every row and then undoes everything.
 */
abstract class Importer
{
    /** Column names in a row are lower-case with underscores ("unit_cost"). */
    abstract public function key(): string;

    abstract public function label(): string;

    /** One paragraph for the import screen: what a row does, how rows are matched to existing records. */
    abstract public function description(): string;

    /** @return list<array{name: string, required: bool|string, help: string, example: string}> required: true, false or 'new' (only for new records) */
    abstract public function columns(): array;

    /** The permission needed somewhere to use this import (the services check each row in detail). */
    abstract public function permission(): string;

    /** @return 'created'|'updated'|'added'|'unchanged' */
    abstract public function apply(Actor $actor, array $row, array $options, ?Model $context): string;

    /** Which record a file goes into, if any: 'stock_count' | 'stock_adjustment'. */
    public function contextType(): ?string
    {
        return null;
    }

    /** Before the rows: e.g. create the adjustment the rows go into. Runs inside the import's transaction. */
    public function prepare(Actor $actor, array $options, ?Model $context): ?Model
    {
        return $context;
    }

    /** Current data in this file's layout (to edit and import again), or null if there is none. */
    public function exportRows(Actor $actor, array $options, ?Model $context): ?iterable
    {
        return null;
    }

    /** Extra options shown on the import screen (Filament form components), with defaults. */
    public function optionDefaults(): array
    {
        return [];
    }

    /** The label of the record a row is about (shown in the check results). */
    public function reference(array $row): string
    {
        return (string) ($row['sku'] ?? $row['code'] ?? $row['name'] ?? '');
    }

    public function headings(): array
    {
        return array_column($this->columns(), 'name');
    }

    // --- helpers for rows ------------------------------------------------------------------------------------------------

    protected static function has(array $row, string $key): bool
    {
        return isset($row[$key]) && trim((string) $row[$key]) !== '';
    }

    protected static function need(array $row, string $key, string $what): string
    {
        if (! self::has($row, $key)) {
            throw new DomainException("Missing {$what} ({$key}).");
        }

        return trim((string) $row[$key]);
    }

    /** yes / no, true / false, 1 / 0, y / n (any case). */
    protected static function bool(string $value, string $column): bool
    {
        $v = strtolower(trim($value));

        return match (true) {
            in_array($v, ['yes', 'y', 'true', '1', 'on'], true) => true,
            in_array($v, ['no', 'n', 'false', '0', 'off'], true) => false,
            default => throw new DomainException("{$column}: write yes or no."),
        };
    }

    /** A number as people type it: "1,250.50", "GHS 12", " 3 ". */
    protected static function number(string $value, string $column): string
    {
        $v = str_replace([',', ' ', "\u{00A0}"], '', preg_replace('/^[A-Za-z₵$€£]+\s*/u', '', trim($value)));
        if (! is_numeric($v)) {
            throw new DomainException("{$column}: \"{$value}\" is not a number.");
        }

        return $v;
    }

    /** 2026-10-22, 22/10/2026 or 22-10-2026 (day first, as in Ghana), optionally with a time. */
    protected static function date(string $value, string $column): string
    {
        $v = trim($value);
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})(.*)$#', $v, $m)) {
            $v = sprintf('%04d-%02d-%02d%s', $m[3], $m[2], $m[1], $m[4]);
        }
        try {
            return \Illuminate\Support\Carbon::parse($v)->toDateTimeString();
        } catch (\Throwable) {
            throw new DomainException("{$column}: \"{$value}\" is not a date (use YYYY-MM-DD).");
        }
    }

    /** "a | b | c" or "a, b" → list */
    protected static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[|;,]/', $value)), fn ($x) => $x !== ''));
    }
}
