<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Support\OfficeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The bell of the new back office (part 43): the person's notifications, as the current bell shows them — newest first,
 * unread marked, each with its "Open" link (at its new-look address) — mark one or all as read, and clear them.
 */
class NotificationsController extends Controller
{
    private const SHOWN = 50;

    private static function item(DatabaseNotification $n): array
    {
        $d = (array) $n->data;
        $url = collect((array) ($d['actions'] ?? []))->pluck('url')->filter()->first() ?? ($d['url'] ?? null);

        return ['id' => $n->id, 'title' => (string) ($d['title'] ?? ''), 'body' => isset($d['body']) ? strip_tags((string) $d['body']) : null,
            'status' => (string) ($d['status'] ?? $d['iconColor'] ?? 'info'), 'at' => $n->created_at?->diffForHumans(), 'read' => $n->read_at !== null,
            'url' => is_string($url) ? OfficeUrl::from($url) : null];
    }

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['unread' => $u->unreadNotifications()->count(),
            'items' => $u->notifications()->latest()->limit(self::SHOWN)->get()->map(fn (DatabaseNotification $n) => self::item($n))->all()]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    /** "Clear": the person's notifications are deleted, as the current bell's Clear. */
    public function clear(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json(['unread' => 0]);
    }
}
