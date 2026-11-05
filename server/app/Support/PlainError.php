<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * What really went wrong, in plain words, for the person it happened to (owner request 2026-11-05). Says which field,
 * rule or part of the program failed, never the values: database messages carry people's data and the query, so only
 * the column and rule are taken from them. Everything else stays in the server log, found by the reference.
 */
final class PlainError
{
    public static function explain(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return self::database($e);
        }
        // Written by us for developers, with no data in them.
        if ($e instanceof LogicException) {
            return 'A fault in the program stopped this: '.Str::limit($e->getMessage(), 200);
        }

        return 'A fault in the program stopped this: '.class_basename($e).' in '.basename($e->getFile()).', line '.$e->getLine().'.';
    }

    private static function database(QueryException $e): string
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $text = (string) ($e->errorInfo[2] ?? '');
        $column = preg_match('/column "([a-z0-9_]+)"/', $text, $m) ? $m[1] : null;
        $table = preg_match('/relation "([a-z0-9_]+)"/', $text, $m) ? $m[1] : null;

        return match (true) {
            $state === '23502' => self::words($column).' was left empty, and '.($table ? 'a '.Str::singular(str_replace('_', ' ', $table)) : 'this record').' cannot be saved without it. Nothing was saved.',
            $state === '23505' => (preg_match('/Key \(([^)]+)\)=/', $text, $m) ? self::words(collect(explode(',', $m[1]))->map(fn ($c) => trim($c))->reject(fn ($c) => $c === 'tenant_id')->last()) : 'That value').' is already used by another record. Nothing was saved.',
            $state === '23503' => 'This is linked to another record that is missing or still in use. Nothing was saved.',
            $state === '23514' => 'A value is outside what is allowed'.(preg_match('/constraint "([a-z0-9_]+)"/', $text, $m) ? ' ('.$m[1].')' : '').'. Nothing was saved.',
            $state === '22001' => 'A value is too long'.(preg_match('/varying\((\d+)\)/', $text, $m) ? " (at most {$m[1]} characters)" : '').'. Nothing was saved.',
            $state === '22003' => 'A number is too large. Nothing was saved.',
            in_array($state, ['22P02', '22007', '22008'], true) => 'A number or date is not in a form the system can read. Nothing was saved.',
            in_array($state, ['40001', '40P01', '55P03'], true) => 'Someone else was changing the same thing at that moment. Nothing was saved: try again.',
            str_starts_with($state, '08') || $state === '57P01' => 'The server could not reach its database. Nothing was saved: try again in a minute.',
            str_starts_with($state, '42') => 'The program and the database do not match, so an update may not have finished. Nothing was saved.',
            default => "The database refused this (code {$state}). Nothing was saved.",
        };
    }

    private static function words(?string $column): string
    {
        return $column ? Str::ucfirst(str_replace('_', ' ', trim(preg_replace('/_(id|minor|bp)$/', '', trim($column)), '_'))) : 'A required detail';
    }
}
