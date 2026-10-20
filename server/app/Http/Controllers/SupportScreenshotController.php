<?php

namespace App\Http\Controllers;

use App\Models\SupportReport;
use App\Models\User;
use App\Security\Authorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The picture of the screen sent with a problem report: for the person who reported it and support readers. */
class SupportScreenshotController extends Controller
{
    public function __invoke(Request $request, SupportReport $report, Authorizer $authz): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->tenant_id === $report->tenant_id, 404);
        abort_unless($report->user_id === $user->id || $authz->allows($user, 'support.view'), 403);
        abort_unless($report->screenshot_path && Storage::disk('local')->exists($report->screenshot_path), 404);

        return Storage::disk('local')->response($report->screenshot_path);
    }
}
