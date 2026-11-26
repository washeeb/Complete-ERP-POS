<?php

use App\Support\OfficeUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Part 42 of the new back office: saved addresses of the current back office (/admin/…) become the new back office's —
 * each person's favourites, home page and Home tiles, and the links in notifications already sent — by the same map the
 * server now uses (App\Support\OfficeUrl). One-time; addresses that are not the back office's are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $to = fn ($u) => is_string($u) ? (string) OfficeUrl::from($u) : $u;

        DB::table('users')->select(['id', 'ui_favourites', 'ui_prefs'])->orderBy('id')->chunk(200, function ($users) use ($to) {
            foreach ($users as $u) {
                $favs = json_decode((string) $u->ui_favourites, true);
                $prefs = json_decode((string) $u->ui_prefs, true);
                $set = [];
                if (is_array($favs)) {
                    $new = array_map(fn ($f) => is_array($f) && isset($f['url']) ? ['url' => $to($f['url'])] + $f : $f, $favs);
                    if ($new !== $favs) {
                        $set['ui_favourites'] = json_encode(array_values($new));
                    }
                }
                if (is_array($prefs)) {
                    $new = $prefs;
                    if (isset($new['home']) && is_string($new['home']) && str_starts_with($new['home'], '/')) {
                        $new['home'] = $to($new['home']);
                    }
                    if (isset($new['office_home']['tiles']) && is_array($new['office_home']['tiles'])) {
                        $new['office_home']['tiles'] = array_map(fn ($t) => is_array($t) && isset($t['url']) ? ['url' => $to($t['url'])] + $t : $t, $new['office_home']['tiles']);
                    }
                    if ($new !== $prefs) {
                        $set['ui_prefs'] = json_encode($new);
                    }
                }
                if ($set) {
                    DB::table('users')->where('id', $u->id)->update($set);
                }
            }
        });

        // Notifications already sent: their "Open" links (and a plain url, when one is stored).
        DB::table('notifications')->whereRaw("data::text like '%/admin%'")->orderBy('id')->chunk(500, function ($rows) use ($to) {
            foreach ($rows as $n) {
                $data = json_decode((string) $n->data, true);
                if (! is_array($data)) {
                    continue;
                }
                $new = $data;
                foreach ((array) ($new['actions'] ?? []) as $i => $a) {
                    if (is_array($a) && isset($a['url'])) {
                        $new['actions'][$i]['url'] = $to($a['url']);
                    }
                }
                if (isset($new['url'])) {
                    $new['url'] = $to($new['url']);
                }
                if ($new !== $data) {
                    DB::table('notifications')->where('id', $n->id)->update(['data' => json_encode($new)]);
                }
            }
        });
    }

    public function down(): void
    {
        // One-way: the current back office's addresses still lead to the same pages.
    }
};
