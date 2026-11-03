<?php

namespace App\Http\Controllers;

use App\Domain\Iot\Sensors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A sensor's readings, posted (or, for devices that can only call a link, sent as a GET with ?temp=&hum=) to its own
 * secret address /api/v1/iot/{token}. JSON or form fields; see Sensors::parse() for the shapes understood.
 */
class IotController extends Controller
{
    public function __invoke(Request $request, string $token, Sensors $sensors): JsonResponse
    {
        $payload = $request->isJson() ? (array) $request->json()->all() : $request->all();
        $sensor = $sensors->receive($token, $payload);

        return $sensor ? response()->json(['ok' => true, 'status' => $sensor->fresh()->status]) : response()->json(['ok' => false], 404);
    }
}
