<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Devices\DeviceService;
use App\Domain\Devices\PosAuthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAuthController extends Controller
{
    public function enroll(Request $request, DeviceService $devices): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'public_key' => ['required', 'string', 'max:4096'],
        ]);

        return response()->json($devices->enroll($data['code'], $data['public_key']), 201);
    }

    public function pinLogin(Request $request, PosAuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'pin' => ['required', 'string', 'max:8'],
        ]);

        return response()->json($auth->pinLogin($request->attributes->get('device'), $data['username'], $data['pin']));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $device = $request->attributes->get('device');

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username],
            'device' => ['id' => $device->id, 'name' => $device->name, 'branch_id' => $device->branch_id, 'till_id' => $device->till_id],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
