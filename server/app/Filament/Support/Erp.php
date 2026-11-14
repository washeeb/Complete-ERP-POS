<?php

namespace App\Filament\Support;

use App\Domain\Access\SentForApproval;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\AccessRequest;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\IndustryContext;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** Glue between Filament screens and the domain layer. Screens never write models directly. */
final class Erp
{
    public static function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function actor(): Actor
    {
        return Actor::user(self::user(), 'web');
    }

    /** With a target: allowed on it. Without: allowed company-wide. */
    public static function can(string $permission, ?Model $target = null): bool
    {
        $user = self::user();

        return $user !== null && app(Authorizer::class)->allows($user, $permission, $target);
    }

    /** For an industry's record (its price lists, product prices…); null = company-level, needs a company-wide grant. */
    public static function canForIndustry(string $permission, ?string $industryId): bool
    {
        $user = self::user();

        return $user !== null && app(Authorizer::class)->allowsForIndustry($user, $permission, $industryId);
    }

    /** For showing a menu item; every write re-checks the exact scope in the domain service. */
    public static function canAnywhere(string $permission): bool
    {
        $user = self::user();

        return $user !== null && app(Authorizer::class)->allowsAnywhere($user, $permission);
    }

    /**
     * Branches this person may see for $permission, narrowed to the industry chosen in the switcher.
     *
     * @return list<string>|null null = all branches
     */
    public static function branchIds(string $permission): ?array
    {
        $user = self::user();
        if (! $user) {
            return [];
        }
        $ids = app(Authorizer::class)->branchIds($user, $permission);
        $industry = IndustryContext::branchIds();
        if ($industry === null) {
            return $ids;
        }

        return $ids === null ? $industry : array_values(array_intersect($ids, $industry));
    }

    /**
     * Run a domain call; turn a rule violation into a readable message and stop the form/action.
     *
     * @template T
     *
     * @param  callable(Actor): T  $call
     * @return T
     */
    /** IT Admin's access change went to the owner or SyncLytics support (owner decision 2026-11-13). */
    public static function sentForApproval(SentForApproval $e): void
    {
        Notification::make()->info()->title('Sent for approval')->persistent()
            ->body($e->request->summary.' The owner or SyncLytics support approves it (People & access → Access requests); it lapses after '.AccessRequest::HOURS.' hours if not answered.')->send();
    }

    public static function run(callable $call, ?string $success = null): mixed
    {
        try {
            $result = $call(self::actor());
        } catch (SentForApproval $e) {
            self::sentForApproval($e);

            return null; // not done yet: the owner or SyncLytics support decides
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        }
        if ($success) {
            Notification::make()->success()->title($success)->send();
        }

        return $result;
    }
}
