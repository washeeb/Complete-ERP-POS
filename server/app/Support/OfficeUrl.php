<?php

namespace App\Support;

/**
 * The address of a page in the new back office (part 42). `of()` gives the address of a page by its current-back-office
 * route (resources/office-routes.json, written from the new back office's own address map by `npm run office:routes`), so
 * there is one map. `from()` turns any current-back-office address (absolute or not; a stored favourite, tile or
 * notification link) into the new one; a search (tableSearch) carries over as q, as the new back office does. Anything
 * that is not a current-back-office address is returned as it is.
 */
final class OfficeUrl
{
    /** @var list<array{0: string, 1: string, 2: list<string>}>|null [regex, office template, parameter names] */
    private static ?array $routes = null;

    private static function routes(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }
        $file = resource_path('office-routes.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $out = [];
        foreach ((array) ($data['routes'] ?? []) as $r) {
            if (! is_string($r['office'] ?? null)) {
                continue;
            }
            $names = [];
            $regex = preg_replace_callback('/\\\\\{([^}]+?)\\\\\}/', function ($m) use (&$names) {
                $names[] = rtrim(stripslashes($m[1]), '?');

                return '([^/]+)';
            }, preg_quote((string) $r['admin'], '#'));
            $out[] = ['#^'.$regex.'/?$#', (string) $r['office'], $names];
        }
        // Fixed addresses before ones with parameters (/admin/products/create before /admin/products/{record}).
        usort($out, fn ($a, $b) => count($a[2]) <=> count($b[2]));

        return self::$routes = $out;
    }

    /** The new address of a current-back-office path such as /admin/sales/0190… (null when it has none). */
    private static function path(string $adminPath): ?string
    {
        foreach (self::routes() as [$regex, $office, $names]) {
            if (preg_match($regex, $adminPath, $m)) {
                foreach ($names as $i => $name) {
                    $office = str_replace('{'.$name.'}', rawurlencode(rawurldecode($m[$i + 1])), $office);
                }

                return $office;
            }
        }

        return null;
    }

    /** Any address: a current-back-office one becomes the new back office's (path and query only); others stay as they are. */
    public static function from(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        $p = parse_url($url);
        $path = '/'.ltrim((string) ($p['path'] ?? ''), '/');
        if (isset($p['host']) && ! in_array($p['host'], [parse_url((string) config('app.url'), PHP_URL_HOST), request()?->getHost()], true)) {
            return $url; // another site
        }
        if ($path !== '/admin' && ! str_starts_with($path, '/admin/')) {
            return $url;
        }
        $office = self::path(rtrim($path, '/') ?: '/admin') ?? '/office/';
        parse_str((string) ($p['query'] ?? ''), $q);
        if (isset($q['tableSearch'])) {
            $q = ['q' => $q['tableSearch']] + array_diff_key($q, ['tableSearch' => true]);
        }

        return $office.($q ? '?'.http_build_query($q) : '');
    }

    /** The page of a current-back-office route template, e.g. of('/admin/sales/{record}', ['record' => $id]). */
    public static function of(string $adminTemplate, array $params = [], array $query = []): string
    {
        $path = preg_replace_callback('/\{([^}]+?)\??\}/', fn ($m) => rawurlencode((string) ($params[$m[1]] ?? '')), $adminTemplate);
        $office = self::path((string) $path) ?? '/office/';

        return $office.($query ? '?'.http_build_query($query) : '');
    }
}
