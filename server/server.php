<?php

/*
 * The router for `php artisan serve` only (the dev preview on port 8010); real installs use Caddy, nginx or IIS and never
 * load this file. Laravel's own router (vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) is used
 * as is, with one correction: PHP's built-in server treats /office/sales as /office/index.html with "/sales" after it,
 * because public/office/ holds the new back office's index.html (the same for /pos/…). Laravel then believed it was
 * installed at /office and found no page "/sales", so every /office or /pos address opened directly (typed, reloaded,
 * bookmarked) gave "Not Found". Here, an address that is not a real file always reaches Laravel through /index.php.
 */
$public = getcwd();
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && ! is_file($public.$uri)) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $public.DIRECTORY_SEPARATOR.'index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    unset($_SERVER['PATH_INFO']);
}

return require __DIR__.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
