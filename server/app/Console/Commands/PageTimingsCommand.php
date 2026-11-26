<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * How fast every back-office page opens for a person (speed sweep, owner request 2026-10-24; the new back office since
 * part 43): a page of it waits for its data, so this times every data call a page makes that needs no record (lists,
 * options, Home, To-do, reports…) and, with --deep, the first record of each list; it lists the slowest with their time
 * and number of database queries — many queries usually means a list that looks something up row by row. Everything
 * runs in a transaction that is rolled back: nothing is changed. Safe on a client's server (outside trading hours on a
 * big database).
 */
class PageTimingsCommand extends Command
{
    protected $signature = 'erp:page-timings {--as= : E-mail or username of the person (default: the owner)} {--deep : Also open the first record of each list} {--top=25 : How many to list} {--errors : List only the calls that answered with an error} {--page= : One data call only (e.g. /office-api/products): its repeated and slowest database queries}';

    protected $description = 'Time every back-office page\'s data for a person (rolled back: changes nothing)';

    /** Data calls that make a file, send something or answer only a search: not a page opening. */
    private const SKIP = '#/(export|file|card|sheet|pdf|download|print|errors|search|customers|units|products|suppliers|services|tags)(/|$)|\.(csv|pdf|xlsx)$#';

    public function handle(Kernel $kernel): int
    {
        ini_set('memory_limit', '1024M'); // every page of the back office in one run
        $who = (string) $this->option('as');
        $user = $who !== '' ? User::withoutGlobalScopes()->where('email', $who)->orWhere('username', $who)->first()
            : User::withoutGlobalScopes()->where('is_active', true)->orderByDesc('security_level')->get()->first(fn (User $u) => ! $u->isSupport());
        if (! $user) {
            $this->error('No such person.');

            return self::FAILURE;
        }
        if ($this->option('page')) {
            return $this->onePage($kernel, $user, (string) $this->option('page'));
        }
        $calls = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'office-api/') && ! str_contains($r->uri(), '{'))
            ->map(fn ($r) => '/'.$r->uri())->reject(fn ($p) => preg_match(self::SKIP, $p))->unique()->sort()->values();
        $rows = [];
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        DB::beginTransaction();
        try {
            $open = function (string $path) use ($kernel, $user, &$queries): array {
                Auth::guard('web')->setUser($user);
                $queries = 0;
                $t = microtime(true);
                DB::beginTransaction(); // a savepoint: a call that fails in the database does not spoil the next ones
                try {
                    $res = $kernel->handle(tap(Request::create($path, 'GET'), fn ($r) => $r->headers->set('Accept', 'application/json')));
                } finally {
                    DB::rollBack();
                }

                return ['path' => $path, 'ms' => (int) round((microtime(true) - $t) * 1000), 'queries' => $queries, 'status' => $res->getStatusCode(), 'body' => (string) $res->getContent()];
            };
            $this->info('Opening '.$calls->count().' page(s) as '.$user->name.'…');
            $bar = $this->output->createProgressBar($calls->count());
            $deep = [];
            foreach ($calls as $path) {
                $r = $open($path);
                if ($this->option('deep') && $r['status'] === 200 && ($id = $this->firstId($r['body'])) && $this->hasPage("{$path}/{$id}")) {
                    $deep[] = "{$path}/{$id}"; // the first record of the list, when the list has a page for one
                }
                $rows[] = array_diff_key($r, ['body' => 0]); // only the figures are kept
                unset($r);
                gc_collect_cycles();
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            foreach (array_unique($deep) as $path) {
                $rows[] = array_diff_key($open($path), ['body' => 0]);
            }
        } finally {
            DB::rollBack();
        }

        $rows = collect($rows)->sortByDesc('ms')->values();
        if ($this->option('errors')) {
            $rows = $rows->where('status', '>=', 500)->values();
        }
        $this->table(['Page data', 'ms', 'Queries', 'Status'], $rows->take((int) $this->option('top'))->map(fn ($r) => [$r['path'], $r['ms'], $r['queries'], $r['status']])->all());
        $this->line(sprintf('%d call(s): median %d ms, slowest %d ms; %d over 1 s; %d answered with an error.', $rows->count(), (int) $rows->median('ms'), (int) $rows->max('ms'),
            $rows->where('ms', '>', 1000)->count(), $rows->where('status', '>=', 500)->count()));

        return self::SUCCESS;
    }

    /** Whether a GET address is a data call of its own (a record's page). */
    private function hasPage(string $path): bool
    {
        try {
            return (bool) Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (\Throwable) {
            return false;
        }
    }

    /** The first record in a list's answer (rows[0].id), if any. */
    private function firstId(string $body): ?string
    {
        $d = json_decode($body, true);
        $id = is_array($d) ? ($d['rows'][0]['id'] ?? null) : null;

        return is_string($id) && preg_match('/^[0-9a-f-]{36}$/', $id) ? $id : null;
    }

    /** One data call: the queries run most often (a list looking something up row by row) and the slowest. */
    private function onePage(Kernel $kernel, User $user, string $path): int
    {
        $log = [];
        DB::listen(function ($q) use (&$log) {
            $log[] = ['sql' => preg_replace(["/'[^']*'/", '/\d+/', '/\?(, \?)+/'], ['?', '?', '?, …'], $q->sql), 'ms' => $q->time];
        });
        DB::beginTransaction();
        try {
            Auth::guard('web')->setUser($user);
            $kernel->handle(tap(Request::create($path, 'GET'), fn ($r) => $r->headers->set('Accept', 'application/json')));
        } finally {
            DB::rollBack();
        }
        $log = collect($log);
        $this->line(count($log).' queries, '.round($log->sum('ms')).' ms in the database.');
        $this->table(['Times', 'ms', 'Query'], $log->groupBy('sql')->map(fn ($g, $sql) => [$g->count(), round($g->sum('ms')), mb_strimwidth($sql, 0, 160, '…')])
            ->sortByDesc(fn ($r) => $r[0] * 1000 + $r[1])->take((int) $this->option('top'))->values()->all());

        return self::SUCCESS;
    }
}
