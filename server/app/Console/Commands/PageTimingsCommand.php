<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * How fast every back-office page opens for a person (speed sweep, owner request 2026-10-24): starts from the
 * dashboard, opens each page the menu shows (and, with --deep, the first row and the New screen of each list), and
 * lists the slowest with their time and number of database queries — many queries on one page usually means a list
 * that looks something up row by row. Everything runs in a transaction that is rolled back: nothing is changed.
 * Safe on a client's server (run it outside trading hours on a big database).
 */
class PageTimingsCommand extends Command
{
    protected $signature = 'erp:page-timings {--as= : E-mail or username of the person (default: the owner)} {--deep : Also open the first row and New screen of each list} {--top=25 : How many to list} {--page= : One page only: its repeated and slowest database queries}';

    protected $description = 'Time every back-office page for a person (rolled back: changes nothing)';

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
                $res = $kernel->handle(Request::create($path, 'GET'));
                $ms = (int) round((microtime(true) - $t) * 1000);
                if ($res->isRedirect() && str_starts_with((string) parse_url((string) $res->headers->get('Location'), PHP_URL_PATH), '/admin')) {
                    return $this->openOnce((string) parse_url((string) $res->headers->get('Location'), PHP_URL_PATH), $kernel, $user, $queries) + ['from' => $path];
                }

                return ['path' => $path, 'ms' => $ms, 'queries' => $queries, 'status' => $res->getStatusCode(), 'html' => (string) $res->getContent()];
            };
            $home = $open('/admin');
            $links = $this->links($home['html']);
            $rows[] = array_diff_key($home, ['html' => 0]);
            $this->info('Opening '.count($links).' page(s) as '.$user->name.'…');
            $bar = $this->output->createProgressBar(count($links));
            $deep = [];
            foreach ($links as $path) {
                $r = $open($path);
                if ($this->option('deep')) {
                    $deep = array_merge($deep, array_slice(array_filter($this->links($r['html']), fn ($p) => preg_match('#^'.preg_quote($path, '#').'/[^/]+(/edit)?$#', $p)), 0, 2));
                }
                $rows[] = array_diff_key($r, ['html' => 0]); // only the figures are kept
                unset($r);
                gc_collect_cycles();
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();
            foreach (array_unique($deep) as $path) {
                $rows[] = array_diff_key($open($path), ['html' => 0]);
            }
        } finally {
            DB::rollBack();
        }

        $rows = collect($rows)->sortByDesc('ms')->values();
        $this->table(['Page', 'ms', 'Queries', 'Status'], $rows->take((int) $this->option('top'))->map(fn ($r) => [$r['path'], $r['ms'], $r['queries'], $r['status']])->all());
        $this->line(sprintf('%d page(s): median %d ms, slowest %d ms; %d over 1 s; %d answered with an error.', $rows->count(), (int) $rows->median('ms'), (int) $rows->max('ms'),
            $rows->where('ms', '>', 1000)->count(), $rows->where('status', '>=', 500)->count()));

        return self::SUCCESS;
    }

    /** One page: the queries run most often (a list looking something up row by row) and the slowest. */
    private function onePage(Kernel $kernel, User $user, string $path): int
    {
        $log = [];
        DB::listen(function ($q) use (&$log) {
            $log[] = ['sql' => preg_replace(["/'[^']*'/", '/\d+/', '/\?(, \?)+/'], ['?', '?', '?, …'], $q->sql), 'ms' => $q->time];
        });
        DB::beginTransaction();
        try {
            Auth::guard('web')->setUser($user);
            $kernel->handle(Request::create($path, 'GET'));
        } finally {
            DB::rollBack();
        }
        $log = collect($log);
        $this->line(count($log).' queries, '.round($log->sum('ms')).' ms in the database.');
        $this->table(['Times', 'ms', 'Query'], $log->groupBy('sql')->map(fn ($g, $sql) => [$g->count(), round($g->sum('ms')), mb_strimwidth($sql, 0, 160, '…')])
            ->sortByDesc(fn ($r) => $r[0] * 1000 + $r[1])->take((int) $this->option('top'))->values()->all());

        return self::SUCCESS;
    }

    /** One redirect followed (e.g. a list that opens its first tab). */
    private function openOnce(string $path, Kernel $kernel, User $user, int &$queries): array
    {
        Auth::guard('web')->setUser($user);
        $queries = 0;
        $t = microtime(true);
        $res = $kernel->handle(Request::create($path, 'GET'));

        return ['path' => $path, 'ms' => (int) round((microtime(true) - $t) * 1000), 'queries' => $queries, 'status' => $res->getStatusCode(), 'html' => (string) $res->getContent()];
    }

    /** @return list<string> back-office pages linked from the page (no sign-out, no files) */
    private function links(string $html): array
    {
        preg_match_all('#href="(?:https?://[^"/]+)?(/admin/[^"\#?]*)"#', $html, $m);

        return array_values(array_filter(array_unique($m[1]), fn ($p) => ! str_contains($p, '/logout') && $p !== '/admin/' && ! preg_match('#\.(csv|pdf|xlsx)$#', $p)));
    }
}
