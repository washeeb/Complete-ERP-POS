<?php

namespace App\Domain\Backup;

use App\Domain\Support\DomainException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * The places a backup is copied to (owner decision 2026-10-29): a folder (USB drive, second disk, network share),
 * S3-compatible storage (Backblaze B2, Wasabi, AWS…), Google Drive, or another server by SFTP. Every destination can
 * put a file, list the backups it holds (newest first), fetch one back, and delete old ones.
 */
final class Destinations
{
    public const TYPES = ['folder' => 'Folder (USB drive, second disk, network share)', 's3' => 'S3-compatible storage (Backblaze B2, Wasabi, AWS)',
        'gdrive' => 'Google Drive', 'sftp' => 'Another server (SFTP)'];

    /** Settings each type needs: key => [label, secret?] */
    public const FIELDS = [
        'folder' => ['path' => ['Folder path, e.g. E:\\Backups or /mnt/usb/backups', false]],
        's3' => ['endpoint' => ['Endpoint, e.g. https://s3.eu-central-003.backblazeb2.com', false], 'region' => ['Region, e.g. eu-central-003', false],
            'bucket' => ['Bucket', false], 'prefix' => ['Folder in the bucket (optional)', false], 'key' => ['Access key ID', false], 'secret' => ['Secret access key', true]],
        'gdrive' => ['client_id' => ['OAuth client ID', false], 'client_secret' => ['OAuth client secret', true], 'refresh_token' => ['Refresh token', true],
            'folder_id' => ['Drive folder ID (from the folder\'s address)', false]],
        'sftp' => ['host' => ['Server address', false], 'port' => ['Port (22)', false], 'username' => ['User name', false], 'password' => ['Password (or leave empty and use a key)', true],
            'private_key' => ['Private key (OpenSSH, optional)', true], 'path' => ['Folder on the server', false]],
    ];

    public static function put(string $type, array $c, string $local, string $name): void
    {
        match ($type) {
            'folder' => self::folder($c, fn ($dir) => copy($local, $dir.DIRECTORY_SEPARATOR.$name) ?: throw new DomainException("Could not write to {$dir}.")),
            's3' => self::s3Request($c, 'PUT', self::s3Key($c, $name), $local),
            'gdrive' => self::gdriveUpload($c, $local, $name),
            'sftp' => self::sftp($c, fn (SFTP $s, $dir) => $s->put("{$dir}/{$name}", $local, SFTP::SOURCE_LOCAL_FILE) ?: throw new DomainException('SFTP upload failed.')),
            default => throw new DomainException('Unknown destination.'),
        };
    }

    /** @return list<string> backup file names held there, newest first */
    public static function list(string $type, array $c): array
    {
        $names = match ($type) {
            'folder' => self::folder($c, fn ($dir) => array_map('basename', glob($dir.DIRECTORY_SEPARATOR.'synclytics-*.synbak') ?: [])),
            's3' => (function () use ($c) {
                $xml = self::s3Request($c, 'GET', '', null, ['list-type' => '2', 'prefix' => self::s3Key($c, 'synclytics-')])->body();
                preg_match_all('#<Key>([^<]+)</Key>#', $xml, $m);

                return array_map('basename', $m[1]);
            })(),
            'gdrive' => array_column(self::gdriveFiles($c), 'name'),
            'sftp' => self::sftp($c, fn (SFTP $s, $dir) => array_values(array_filter((array) $s->nlist($dir), fn ($n) => str_starts_with($n, 'synclytics-') && str_ends_with($n, '.synbak')))),
            default => [],
        };
        $names = array_values(array_filter($names, fn ($n) => preg_match('/^synclytics-.*\.synbak$/', $n)));
        rsort($names); // names carry the date and time, so newest first

        return $names;
    }

    public static function get(string $type, array $c, string $name, string $local): void
    {
        match ($type) {
            'folder' => self::folder($c, fn ($dir) => copy($dir.DIRECTORY_SEPARATOR.$name, $local) ?: throw new DomainException('Could not read the backup.')),
            's3' => file_put_contents($local, self::s3Request($c, 'GET', self::s3Key($c, $name))->body()),
            'gdrive' => file_put_contents($local, Http::withToken(self::gdriveToken($c))->timeout(600)
                ->get('https://www.googleapis.com/drive/v3/files/'.(collect(self::gdriveFiles($c))->firstWhere('name', $name)['id'] ?? throw new DomainException('Not found on Google Drive.')), ['alt' => 'media'])->throw()->body()),
            'sftp' => self::sftp($c, fn (SFTP $s, $dir) => $s->get("{$dir}/{$name}", $local) ?: throw new DomainException('SFTP download failed.')),
            default => throw new DomainException('Unknown destination.'),
        };
    }

    public static function delete(string $type, array $c, string $name): void
    {
        match ($type) {
            'folder' => self::folder($c, fn ($dir) => @unlink($dir.DIRECTORY_SEPARATOR.$name)),
            's3' => self::s3Request($c, 'DELETE', self::s3Key($c, $name)),
            'gdrive' => ($id = collect(self::gdriveFiles($c))->firstWhere('name', $name)['id'] ?? null)
                ? Http::withToken(self::gdriveToken($c))->delete("https://www.googleapis.com/drive/v3/files/{$id}") : null,
            'sftp' => self::sftp($c, fn (SFTP $s, $dir) => $s->delete("{$dir}/{$name}")),
            default => null,
        };
    }

    /** Check the settings work by writing, listing and removing a small test file. */
    public static function test(string $type, array $c): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'bt');
        file_put_contents($tmp, 'Synclytics backup test');
        $name = 'synclytics-test-'.Str::lower(Str::random(6)).'.synbak';
        try {
            self::put($type, $c, $tmp, $name);
            if (! in_array($name, self::list($type, $c), true)) {
                throw new DomainException('The test file was written but cannot be seen there.');
            }
            self::delete($type, $c, $name);
        } finally {
            @unlink($tmp);
        }
    }

    private static function folder(array $c, callable $do): mixed
    {
        $dir = rtrim((string) ($c['path'] ?? ''), '\\/');
        if ($dir === '' || ! is_dir($dir) || ! is_writable($dir)) {
            throw new DomainException("The folder {$dir} is not there or cannot be written (is the USB drive plugged in?).");
        }

        return $do($dir);
    }

    // --- S3-compatible (AWS Signature Version 4) ---------------------------------------------------------------------

    private static function s3Key(array $c, string $name): string
    {
        $prefix = trim((string) ($c['prefix'] ?? ''), '/');

        return ($prefix !== '' ? $prefix.'/' : '').$name;
    }

    /** $file: the local file to upload (PUT), streamed from disk. */
    private static function s3Request(array $c, string $method, string $key, ?string $file = null, array $query = []): Response
    {
        $endpoint = rtrim((string) ($c['endpoint'] ?? ''), '/');
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (! $host || empty($c['bucket']) || empty($c['key']) || empty($c['secret'])) {
            throw new DomainException('Fill in the endpoint, bucket and keys.');
        }
        $path = '/'.$c['bucket'].($key !== '' ? '/'.implode('/', array_map('rawurlencode', explode('/', $key))) : '/'); // path-style: works with every S3-compatible service
        $headers = self::s3Sign($method, $host, $path, $query, $file ? hash_file('sha256', $file) : hash('sha256', ''), (string) ($c['region'] ?: 'us-east-1'), (string) $c['key'], (string) $c['secret'], gmdate('Ymd\THis\Z'), payloadIsHash: true);
        $url = $endpoint.$path.($query ? '?'.self::canonicalQuery($query) : '');
        try {
            $req = Http::withHeaders($headers)->timeout(600);
            $res = match ($method) {
                'PUT' => $req->withBody(Utils::streamFor(fopen((string) $file, 'rb')), 'application/octet-stream')->put($url),
                'DELETE' => $req->delete($url),
                default => $req->get($url),
            };
        } catch (Throwable $e) {
            throw new DomainException('Storage not reachable: '.Str::limit($e->getMessage(), 150));
        }
        if (! $res->successful()) {
            preg_match('#<Message>([^<]+)</Message>#', $res->body(), $m);
            throw new DomainException("Storage answered {$res->status()}".(isset($m[1]) ? ": {$m[1]}" : '.'));
        }

        return $res;
    }

    /**
     * AWS Signature Version 4 headers for one request (also used by Backblaze B2, Wasabi, Cloudflare R2…).
     *
     * @return array<string, string>
     */
    public static function s3Sign(string $method, string $host, string $path, array $query, string $body, string $region, string $key, string $secret, string $amzDate, array $extraHeaders = [], bool $payloadIsHash = false): array
    {
        $date = substr($amzDate, 0, 8);
        $payloadHash = $payloadIsHash ? $body : hash('sha256', $body);
        $headers = array_change_key_case($extraHeaders + ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate], CASE_LOWER);
        ksort($headers);
        $canonicalHeaders = implode('', array_map(fn ($k, $v) => $k.':'.trim((string) $v)."\n", array_keys($headers), $headers));
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $path, self::canonicalQuery($query), $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$region}/s3/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$secret, true), true), true), true);
        $signature = hash_hmac('sha256', $toSign, $k);
        unset($headers['host']);

        return $headers + ['Authorization' => "AWS4-HMAC-SHA256 Credential={$key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}"];
    }

    private static function canonicalQuery(array $query): string
    {
        ksort($query);

        return implode('&', array_map(fn ($k, $v) => rawurlencode((string) $k).'='.rawurlencode((string) $v), array_keys($query), $query));
    }

    // --- Google Drive (OAuth refresh token of the client's own Google Cloud app) ----------------------------------------

    private static function gdriveToken(array $c): string
    {
        $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', ['client_id' => $c['client_id'] ?? '', 'client_secret' => $c['client_secret'] ?? '',
            'refresh_token' => $c['refresh_token'] ?? '', 'grant_type' => 'refresh_token']);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new DomainException('Google refused the sign-in: '.($res->json('error_description') ?? $res->json('error') ?? $res->status()));
        }

        return (string) $res->json('access_token');
    }

    private static function gdriveFiles(array $c): array
    {
        $folder = str_replace("'", '', (string) ($c['folder_id'] ?? ''));

        return Http::withToken(self::gdriveToken($c))->timeout(30)->get('https://www.googleapis.com/drive/v3/files', [
            'q' => "'{$folder}' in parents and name contains 'synclytics-' and trashed = false", 'fields' => 'files(id,name)', 'pageSize' => 1000,
        ])->throw()->json('files') ?? [];
    }

    private static function gdriveUpload(array $c, string $local, string $name): void
    {
        $token = self::gdriveToken($c);
        $session = Http::withToken($token)->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream'])->timeout(30)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', ['name' => $name, 'parents' => [$c['folder_id'] ?? '']]);
        $url = $session->header('Location');
        if (! $session->successful() || ! $url) {
            throw new DomainException('Google Drive did not accept the upload ('.$session->status().').');
        }
        $res = Http::withToken($token)->withBody(Utils::streamFor(fopen($local, 'rb')), 'application/octet-stream')->timeout(1800)->put($url);
        if (! $res->successful()) {
            throw new DomainException('Google Drive upload failed ('.$res->status().').');
        }
    }

    // --- SFTP ----------------------------------------------------------------------------------------------------------

    private static function sftp(array $c, callable $do): mixed
    {
        $s = new SFTP((string) ($c['host'] ?? ''), (int) ($c['port'] ?: 22), 30);
        $auth = ! empty($c['private_key']) ? PublicKeyLoader::load($c['private_key'], $c['password'] ?: false) : (string) ($c['password'] ?? '');
        try {
            if (! $s->login((string) ($c['username'] ?? ''), $auth)) {
                throw new DomainException('The SFTP server refused the user name or password/key.');
            }
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DomainException('SFTP server not reachable: '.Str::limit($e->getMessage(), 150));
        }
        $dir = rtrim((string) ($c['path'] ?: '.'), '/');

        return $do($s, $dir);
    }
}
