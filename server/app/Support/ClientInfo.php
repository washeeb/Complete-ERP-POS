<?php

namespace App\Support;

use App\Models\Computer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Which computer a request came from, for the audit log and the device list. A browser tells only its kind and system
 * (and the server sees the network's address: in a cloud setup that is the shop's internet router, the same for every PC
 * there). The desktop till app also reports the computer's name, network card (MAC) address and address on the shop
 * network in the X-SES-Client header. That header is what the computer says about itself: good for telling tills
 * apart, not proof of identity.
 */
final class ClientInfo
{
    /** Where an address is (town, network owner): a page the administrator opens; nothing is sent by the system. */
    public static function lookupUrl(?string $ip): ?string
    {
        return $ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? "https://ipinfo.io/{$ip}" : null;
    }

    /** @return array{computer_key?: string, browser?: string, computer?: string, mac?: string, local_ip?: string, app?: string}|null */
    public static function current(): ?array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return self::from(request());
    }

    public static function from(?Request $request): ?array
    {
        if (! $request) {
            return null;
        }
        $out = [];
        if ($ua = (string) $request->userAgent()) {
            $out['browser'] = self::browser($ua);
        }
        $key = (string) ($request->attributes->get('ses_computer') ?: $request->cookie('ses_computer'));
        if (Str::isUuid($key)) {
            $out['computer_key'] = $key;
        }
        // The desktop till app reports in a header (till) and a cookie (back office opened in the app).
        $reported = json_decode((string) ($request->header('X-SES-Client') ?: rawurldecode((string) $request->cookie('ses_client', ''))), true);
        if (is_array($reported)) {
            $clean = fn ($v, string $pattern) => is_string($v) && preg_match($pattern, $v) ? $v : null;
            $out += array_filter([
                'computer' => $clean($reported['computer'] ?? null, '/^[\w .\-]{1,63}$/u'),
                'mac' => isset($reported['mac']) && is_string($reported['mac']) && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $reported['mac']) ? strtoupper(str_replace('-', ':', $reported['mac'])) : null,
                'local_ip' => isset($reported['local_ip']) && is_string($reported['local_ip']) && filter_var($reported['local_ip'], FILTER_VALIDATE_IP) ? $reported['local_ip'] : null,
                'app' => $clean($reported['app'] ?? null, '/^[\w .\-()]{1,40}$/u'),
            ]);
        }

        return $out ?: null;
    }

    /** "Chrome 141 on Windows", "Safari on iPhone": enough to tell devices apart, nothing more. */
    public static function browser(string $ua): string
    {
        $name = match (true) {
            (bool) preg_match('#Edg/(\d+)#', $ua, $m) => 'Edge '.$m[1],
            (bool) preg_match('#OPR/(\d+)#', $ua, $m) => 'Opera '.$m[1],
            (bool) preg_match('#Firefox/(\d+)#', $ua, $m) => 'Firefox '.$m[1],
            (bool) preg_match('#Chrome/(\d+)#', $ua, $m) => 'Chrome '.$m[1],
            (bool) preg_match('#Version/(\d+).*Safari#', $ua, $m) => 'Safari '.$m[1],
            default => 'Other browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'another system',
        };

        return "{$name} on {$os}";
    }

    /** One line for lists: "Front desk PC · FRONT-PC (192.168.1.20) · Edge 141 on Windows". */
    public static function summary(?array $client, ?string $ip = null): ?string
    {
        $client ??= [];
        $named = isset($client['computer_key']) ? (Computer::labels()[$client['computer_key']] ?? null) : null;
        $where = trim(($named ? $named.' · ' : '').($client['computer'] ?? '').(isset($client['local_ip']) ? " ({$client['local_ip']})" : ''), ' ·');
        $parts = array_values(array_filter([$where ?: null, $client['browser'] ?? null, $ip ? "from {$ip}" : null]));

        return $parts ? implode(' · ', $parts) : null;
    }
}
