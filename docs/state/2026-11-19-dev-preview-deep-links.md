## Dev preview deep links (branch `dev-preview-deep-links`)
- `server/server.php` is our router for `php artisan serve` (Laravel uses it instead of its own when present). It sends any address that is not a real file to `/index.php`, then hands over to Laravel's own router.
- A running preview keeps the router it started with: restart "ERP preview" after pulling this.
