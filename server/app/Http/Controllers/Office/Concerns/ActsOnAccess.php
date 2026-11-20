<?php

namespace App\Http\Controllers\Office\Concerns;

use App\Domain\Access\SentForApproval;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Models\AccessRequest;
use App\Security\ReconfirmNeeded;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * For the users and roles screens: run a change in one transaction and answer as JSON. A change an IT Admin makes is
 * sent to the owner or SyncLytics support for approval: AccessService saves the request and then says so, so that is
 * caught inside the transaction (the request is kept) and answered {pending: true, message}, with nothing changed yet.
 */
trait ActsOnAccess
{
    private function act(callable $fn): JsonResponse
    {
        try {
            return response()->json(DB::transaction(function () use ($fn) {
                try {
                    return $fn(Actor::user(request()->user(), 'web'));
                } catch (SentForApproval $e) {
                    return ['pending' => true, 'message' => $e->request->summary.' The owner or SyncLytics support approves it (People & access → Access requests); it lapses after '
                        .AccessRequest::HOURS.' hours if not answered.'];
                }
            }));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(bool $can): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless($can, 403, 'You cannot see this.');
    }
}
