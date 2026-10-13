<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notes every request slower than ERP_SLOW_REQUEST_MS (1 second by default), with how many database queries it ran,
 * for System → Health. Slow pages are then found from real use instead of complaints. Recording happens after the
 * answer has been sent, and never stops a request.
 */
class RecordSlowRequests
{
    private int $queries = 0;

    private float $start = 0;

    private bool $listening = false;

    public function handle(Request $request, Closure $next): Response
    {
        $this->start = microtime(true);
        $this->queries = 0;
        if (! $this->listening) { // one listener per process (the middleware is a singleton, so terminate() sees the same counts)
            DB::listen(function () {
                $this->queries++;
            });
            $this->listening = true;
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $ms = (int) round((microtime(true) - $this->start) * 1000);
        if ($ms < (int) config('erp.slow_request_ms', 1000)) {
            return;
        }
        try {
            $route = $request->route();
            $path = $route?->uri() ?? $request->path();
            // A screen action arrives as a Livewire update: name the screen that was busy.
            if (str_contains($path, 'livewire') && is_string($snap = $request->input('components.0.snapshot'))) {
                $name = json_decode($snap, true)['memo']['name'] ?? null;
                $path .= $name ? " ({$name})" : '';
            }
            DB::table('slow_requests')->insert(['tenant_id' => $request->user()?->tenant_id, 'user_id' => $request->user()?->getKey(),
                'method' => $request->method(), 'path' => mb_substr('/'.ltrim($path, '/'), 0, 255), 'ms' => $ms, 'queries' => $this->queries, 'created_at' => now()]);
        } catch (Throwable) {
            // never let the speed log break anything
        }
    }
}
