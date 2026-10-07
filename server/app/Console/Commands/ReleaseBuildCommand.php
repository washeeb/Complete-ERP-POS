<?php

namespace App\Console\Commands;

use App\Domain\Licensing\ReleaseManifest;
use App\Domain\Licensing\Signer;
use App\Domain\Licensing\Vendor\VendorKeys;
use App\Domain\Licensing\Watermark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Synclytics only: build the copy delivered to one customer (owner decision 2026-10-15).
 *
 *  1. the committed server code (git HEAD — nothing uncommitted, no .env, no secrets);
 *  2. a hidden build stamp in every PHP file of app/ (zero-width characters in a trailing comment), so a leaked
 *     copy names the customer it was given to (`license:trace`);
 *  3. a release manifest: the hashes of the files that enforce licensing, signed with the Synclytics key —
 *     the installation refuses to run normally if they are changed, and only trusts production keys;
 *  4. optionally the till app (pos/dist), built with the same stamp.
 */
class ReleaseBuildCommand extends Command
{
    protected $signature = 'release:build {--customer= : who this copy is for} {--license= : their licence id, if known}
        {--key= : path to the private key file} {--out= : output folder (default ../dist)} {--with-pos : also build the till app}';

    protected $description = 'Synclytics only: build a stamped, signed release for one customer';

    public function handle(): int
    {
        $customer = trim((string) $this->option('customer'));
        if ($customer === '') {
            $this->error('Give --customer.');

            return self::FAILURE;
        }
        $keys = VendorKeys::load($this->option('key'));
        if ($keys['kid'] === 'dev' || ! array_key_exists($keys['kid'], (array) config('license.public_keys'))) {
            $this->error("Key {$keys['kid']} is not a production key listed in config/license.php; installations would not trust this build.");

            return self::FAILURE;
        }
        $build = 'B'.strtoupper(Str::random(8));
        $repo = dirname(base_path());
        $out = rtrim((string) ($this->option('out') ?: $repo.DIRECTORY_SEPARATOR.'dist'), '/\\');
        $dir = $out.DIRECTORY_SEPARATOR.$build;
        @mkdir($dir, 0755, true);

        // 1. Committed code only.
        $tar = $dir.DIRECTORY_SEPARATOR.'server.tar';
        $r = Process::path($repo)->run(['git', 'archive', '--format=tar', '-o', $tar, 'HEAD:server']);
        if (! $r->successful()) {
            $this->error('git archive failed: '.$r->errorOutput());

            return self::FAILURE;
        }
        $root = $dir.DIRECTORY_SEPARATOR.'server';
        (new PharData($tar))->extractTo($root);
        unlink($tar);
        $commit = trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->output());

        // 2. Hidden stamp in the code.
        $stamp = "\n// ".Watermark::hide("{$build}|{$customer}")."\n";
        $n = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.'app', RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                file_put_contents($f->getPathname(), rtrim((string) file_get_contents($f->getPathname()))."\n".$stamp, LOCK_EX);
                $n++;
            }
        }

        // 3. Signed manifest of the protected files.
        $manifest = ['build_id' => $build, 'customer' => $customer, 'license_id' => $this->option('license') ?: null, 'commit' => $commit,
            'created_at' => now()->toIso8601String(), 'kid' => $keys['kid'], 'files' => ReleaseManifest::hashes($root)];
        $manifest['sig'] = Signer::sign($manifest, $keys['secret']);
        @mkdir(dirname($root.DIRECTORY_SEPARATOR.ReleaseManifest::PATH), 0755, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.ReleaseManifest::PATH, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 4. Till app with the same stamp.
        if ($this->option('with-pos')) {
            $pos = $repo.DIRECTORY_SEPARATOR.'pos';
            $b = Process::path($pos)->env(['VITE_BUILD_STAMP' => $build])->timeout(600)->run(['npm', 'run', 'build']);
            if (! $b->successful()) {
                $this->error('Till build failed: '.$b->errorOutput());

                return self::FAILURE;
            }
            $this->copyDir($pos.DIRECTORY_SEPARATOR.'dist', $dir.DIRECTORY_SEPARATOR.'pos');
        }

        $zip = $out.DIRECTORY_SEPARATOR.'synclytics-'.Str::slug($customer).'-'.$build.'.zip';
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $z->addFile($f->getPathname(), str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1)));
            }
        }
        $z->close();
        DB::table('vendor_builds')->insert(['id' => $build, 'customer' => $customer, 'license_id' => $this->option('license') ?: null, 'commit' => $commit,
            'built_by' => get_current_user(), 'created_at' => now()]);
        $this->info("Build {$build} for {$customer}: {$zip} ({$n} files stamped, ".count($manifest['files']).' files guarded).');
        $this->line('Install it with `composer install --no-dev`, then `php artisan migrate --force`; the customer activates with their licence key.');

        return self::SUCCESS;
    }

    private function copyDir(string $from, string $to): void
    {
        @mkdir($to, 0755, true);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $target = $to.DIRECTORY_SEPARATOR.substr($f->getPathname(), strlen($from) + 1);
            $f->isDir() ? @mkdir($target, 0755, true) : copy($f->getPathname(), $target);
        }
    }
}
