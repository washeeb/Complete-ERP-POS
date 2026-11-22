<?php

namespace App\Http\Controllers\Office;

use App\Domain\Iot\Clouds;
use App\Domain\Iot\Sensors;
use App\Domain\Office\SensorsScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\IotAlarm;
use App\Models\IotConnection;
use App\Models\IotSensor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sensors in the new back office (App\Domain\Office\SensorsScreen): sensors (save, record a check, the address and a new
 * one, archive), alarms (acknowledge), the temperature log and sensor clouds (save, read now) — each through the Sensors
 * and Clouds services as the current pages.
 */
class SensorsController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): SensorsScreen
    {
        $this->allowed($can);

        return new SensorsScreen($r->user());
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SensorsScreen::canView())->sensors($request->query()));
    }

    public function show(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? SensorsScreen::canView() : IotSensorResource::canCreate());

        return response()->json($screen->sensor($id ? $screen->query()->findOrFail($id) : null));
    }

    public function save(Request $request, Sensors $sensors, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? SensorsScreen::canManage() : IotSensorResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'kind' => ['required', Rule::in(array_keys(IotSensor::KINDS))], 'branch_id' => ['required', 'uuid'],
            'asset_id' => ['nullable', 'uuid'], 'min_temp' => ['nullable', 'numeric'], 'max_temp' => ['nullable', 'numeric'], 'min_humidity' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_humidity' => ['nullable', 'numeric', 'min:0', 'max:100'], 'delay_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'silent_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'], 'connection' => ['required', Rule::in(array_keys(IotSensor::CONNECTIONS))],
            'connection_id' => ['nullable', 'uuid'], 'external_id' => ['nullable', 'string', 'max:120']],
            ['name.*' => 'Name the sensor (e.g. “Vaccine fridge, dispensary”).', 'branch_id.*' => 'Choose the branch the sensor is at.']);
        $s = $id ? $screen->query()->findOrFail($id) : new IotSensor;
        $d['asset_id'] = ($d['asset_id'] ?? '') ?: null;

        return $this->act(fn (Actor $actor) => ['id' => $sensors->save($actor, $s, $d)->id, 'message' => 'Saved.']);
    }

    /** check (seen, ok, note) · new_address. */
    public function action(Request $request, string $id, Sensors $sensors): JsonResponse
    {
        $screen = $this->screen($request, SensorsScreen::canView());
        $s = $screen->query()->findOrFail($id);
        if ($request->input('action') === 'new_address') {
            $this->allowed(SensorsScreen::canManage() && $s->connection === 'direct');

            return $this->act(fn (Actor $actor) => ['message' => $sensors->newToken($actor, $s) ? 'New address made.' : '']);
        }
        abort_unless($request->input('action') === 'check', 422, 'Unknown action.');
        $this->allowed(SensorsScreen::canRespond() && $s->measuresTemperature());
        $d = $request->validate(['seen' => ['nullable', 'numeric'], 'ok' => ['boolean'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->act(fn (Actor $actor) => ['message' => $sensors->check($actor, $s, ($d['seen'] ?? null) === null ? null : (float) $d['seen'], (bool) ($d['ok'] ?? true),
            ($d['note'] ?? '') ?: null) ? 'Check signed.' : '']);
    }

    /** The address a direct sensor posts to: for people who manage sensors only. */
    public function address(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, SensorsScreen::canManage());
        $s = $screen->query()->findOrFail($id);
        abort_unless($s->connection === 'direct' && $s->token, 404);

        return response()->json($screen->address($s));
    }

    public function archive(Request $request, string $id, ArchiveService $svc): JsonResponse
    {
        $s = $this->screen($request, SensorsScreen::canView())->query()->findOrFail($id);
        $d = $request->validate(['restore' => ['boolean'], 'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->allowed($svc->allowed($request->user(), $s));

        return $this->act(function (Actor $actor) use ($svc, $s, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $s) : $svc->archive($actor, $s, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Alarms

    public function alarms(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SensorsScreen::canView())->alarms($request->query()));
    }

    public function acknowledge(Request $request, string $id, Sensors $sensors): JsonResponse
    {
        $this->screen($request, SensorsScreen::canRespond());
        $a = IotSensorResource::scoped(IotAlarm::query())->findOrFail($id);
        $d = $request->validate(['action' => ['required', Rule::in(array_keys(IotAlarm::ACTIONS))], 'notes' => ['nullable', 'string', 'max:2000']], ['action.*' => 'Say what was done.']);

        return $this->act(fn (Actor $actor) => ['message' => $sensors->acknowledge($actor, $a, $d['action'], ($d['notes'] ?? '') ?: null) ? 'Acknowledged.' : '']);
    }

    // -------------------------------------------------------------------------------------------- Temperature log

    public function log(Request $request): JsonResponse
    {
        $screen = $this->screen($request, SensorsScreen::canLog());
        $sensor = $request->query('sensor');
        $month = $request->query('month');

        return response()->json($screen->log(is_string($sensor) && $sensor !== '' ? $sensor : null, is_string($month) ? $month : null));
    }

    // -------------------------------------------------------------------------------------------- Sensor clouds

    public function clouds(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SensorsScreen::canClouds())->clouds());
    }

    public function cloudSave(Request $request, Clouds $clouds, ?string $id = null): JsonResponse
    {
        $this->screen($request, SensorsScreen::canClouds());
        $d = $request->validate(['provider' => ['required', Rule::in(array_keys(IotConnection::PROVIDERS))], 'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'], 'password' => ['nullable', 'string', 'max:255'], 'client_id' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'], 'region' => ['nullable', Rule::in(array_keys(IotConnection::TUYA_REGIONS))], 'is_active' => ['boolean']]);
        $c = $id ? IotConnection::findOrFail($id) : new IotConnection;
        $body = ['provider' => $d['provider'], 'name' => $d['name'] ?? null, 'region' => $d['region'] ?? null, 'is_active' => (bool) ($d['is_active'] ?? true),
            'credentials' => array_intersect_key($d, array_flip(['email', 'password', 'client_id', 'secret']))];

        return $this->act(fn (Actor $actor) => ['id' => $clouds->save($actor, $c, $body)->id, 'message' => 'Saved.']);
    }

    /** Read now: fetch the readings at once (as the current button); a problem comes back as the account's error. */
    public function cloudRead(Request $request, string $id, Clouds $clouds): JsonResponse
    {
        $this->screen($request, SensorsScreen::canClouds());
        $c = IotConnection::findOrFail($id);
        $n = $clouds->poll($c);
        $c->refresh();

        return $c->last_error
            ? response()->json(['error' => 'cloud', 'detail' => 'Could not read the readings: '.$c->last_error], 422)
            : response()->json(['message' => "{$n} reading(s) read."]);
    }
}
