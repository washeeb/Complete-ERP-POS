<?php

namespace App\Support;

use App\Domain\Access\AccessService;
use App\Domain\Support\Actor;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Security\ScopeType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Staff cards (credit-card size, 85.6 × 54 mm) for the till: the company's colour and logo, the person's photo, name, role
 * and branch, and a barcode the till's scanner reads in place of the username (owner request 2026-11-15: "generated and
 * printed straight away"). A barcode (Code 128), not a QR code: every till scanner reads it, laser or camera.
 *
 * A card's code is kept only as a hash, so it can be printed only when it is made: new cards are made in a batch, their
 * codes held (encrypted, for a few minutes) for one print page, then forgotten. The code is never printed as text.
 */
final class StaffCard
{
    public const LAYOUTS = ['a4' => 'A4 sheet, 10 cards with cut lines (any office printer)', 'card' => 'Card printer (PVC cards, CR80, one per card)'];

    /** Minutes a batch of new cards waits to be printed. */
    public const MINUTES = 10;

    /**
     * New cards for these people (their old cards stop working at once); returns the one-time print address.
     *
     * @param  iterable<User>  $users
     */
    public static function issue(Actor $actor, iterable $users, string $layout): string
    {
        $codes = [];
        foreach ($users as $user) {
            $codes[$user->id] = app(AccessService::class)->issueBadge($actor, $user);
        }
        $token = Str::random(40);
        Cache::put("staff-cards:{$token}", Crypt::encrypt(['by' => $actor->id, 'layout' => isset(self::LAYOUTS[$layout]) ? $layout : 'a4', 'codes' => $codes]),
            now()->addMinutes(self::MINUTES));

        return route('print.staff-cards', ['token' => $token]);
    }

    /** The batch, once: the print page takes it, and the codes are gone. @return array{by: string, layout: string, codes: array<string, string>}|null */
    public static function take(string $token, string $userId): ?array
    {
        $sealed = Cache::get("staff-cards:{$token}");
        $batch = $sealed ? Crypt::decrypt($sealed) : null;
        if (! $batch || $batch['by'] !== $userId) {
            return null; // someone else's link opens nothing, and leaves the batch for its owner
        }
        Cache::forget("staff-cards:{$token}");

        return $batch;
    }

    /** What goes on one person's card. */
    public static function face(User $user, string $code): array
    {
        $grant = RoleAssignment::withoutGlobalScopes()->where('user_id', $user->id)->active()->with('role')->get()
            ->sortByDesc(fn (RoleAssignment $g) => $g->role?->security_level ?? 0)->first();
        $where = match ($grant?->scope_type) {
            null => null,
            ScopeType::Tenant => 'All branches',
            default => Str::after($grant->scopeLabel(), ': '),
        };
        $photo = $user->avatar_path && Storage::disk('local')->exists($user->avatar_path)
            ? 'data:image/jpeg;base64,'.base64_encode((string) Storage::disk('local')->get($user->avatar_path)) : null;
        $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
        // Bars only, wide enough for any scanner: the code stretches to the width it is given on the card.
        $bars = (new BarcodeGeneratorSVG)->getBarcode(strtoupper($code), BarcodeGeneratorSVG::TYPE_CODE_128, 2, 40);
        $bars = preg_replace(['/<\?xml.*?\?>\s*/s', '/<!DOCTYPE.*?>\s*/s', '/<desc>.*?<\/desc>\s*/s'], '', $bars); // the code is never shown as text
        $bars = str_replace('<svg ', '<svg preserveAspectRatio="none" aria-hidden="true" ', $bars);

        return ['name' => $user->name, 'role' => $grant?->role?->name, 'where' => $where, 'photo' => $photo, 'initials' => $initials, 'barcode' => $bars];
    }
}
