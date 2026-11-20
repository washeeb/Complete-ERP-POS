<?php

namespace App\Http\Controllers\Office;

use App\Domain\Devices\DeviceService;
use App\Domain\Office\DevicesScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Remote\DeviceHealth;
use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Computer;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Devices and computers in the new back office (App\Domain\Office\DevicesScreen), as the current screens. The enrolment
 * code is in the answer to Register or New code only, shown once; it is not kept anywhere the screen can read again.
 */
class DevicesController extends Controller
{
    use ActsOnAccess;

    public function index(Request $request): JsonResponse
    {
        $this->allowed(DevicesScreen::canDevices());

        return response()->json((new DevicesScreen($request->user()))->devices($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed(DevicesScreen::canDevices());

        return response()->json((new DevicesScreen($request->user()))->options($request->query('branch')));
    }

    public function store(Request $request, DeviceService $devices): JsonResponse
    {
        $this->allowed(DevicesScreen::canDevices());
        $d = $request->validate(['branch_id' => ['required', 'uuid'], 'till_id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:80'], 'kind' => ['required', 'string', 'max:20']],
            ['branch_id.required' => 'Choose the branch.', 'name.required' => 'Name the device.']);
        abort_unless(isset(DevicesScreen::branches()[$d['branch_id']]), 403, 'You cannot register devices at that branch.');

        return $this->act(function (Actor $actor) use ($devices, $d) {
            [$device, $code] = $devices->register($actor, $d);

            return ['code' => $code, 'expires' => $device->enrollment_expires_at?->toIso8601String(), 'message' => "{$device->name} registered."];
        });
    }

    private function device(string $id): Device
    {
        $this->allowed(DevicesScreen::canDevices());

        return DevicesScreen::query()->find($id) ?? abort(404);
    }

    public function reissue(string $device, DeviceService $devices): JsonResponse
    {
        $dev = $this->device($device);

        return $this->act(function (Actor $actor) use ($devices, $dev) {
            $code = $devices->reissueCode($actor, $dev);

            return ['code' => $code, 'expires' => $dev->fresh()->enrollment_expires_at?->toIso8601String(), 'message' => "New code for {$dev->name}."];
        });
    }

    public function revoke(Request $request, string $device, DeviceService $devices): JsonResponse
    {
        $dev = $this->device($device);
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why: lost, stolen, replaced…']);

        return $this->act(function (Actor $actor) use ($devices, $dev, $d) {
            $devices->revoke($actor, $dev, $d['reason']);

            return ['message' => "{$dev->name} revoked: it and everyone signed in on it are signed out."];
        });
    }

    public function fix(Request $request, string $device, DeviceHealth $health): JsonResponse
    {
        $dev = $this->device($device);
        $d = $request->validate(['command' => ['required', Rule::in(array_keys(DeviceCommand::COMMANDS))], 'reason' => ['nullable', 'string', 'max:255']]);

        return $this->act(function (Actor $actor) use ($health, $dev, $d) {
            $health->queue($actor, $dev, $d['command'], $d['reason'] ?? null);

            return ['message' => 'Fix sent: the till does it within about 5 minutes and reports back.'];
        });
    }

    public function fixes(Request $request, string $device): JsonResponse
    {
        $dev = $this->device($device);

        return response()->json(['rows' => (new DevicesScreen($request->user()))->fixes($dev)]);
    }

    public function archive(Request $request, string $device, string $step, ArchiveService $archive): JsonResponse
    {
        $dev = $this->device($device);
        $d = $request->validate(['reason' => [$step === 'archive' ? 'required' : 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);

        return $this->act(function (Actor $actor) use ($archive, $dev, $step, $d) {
            $step === 'archive' ? $archive->archive($actor, $dev, $d['reason']) : $archive->restore($actor, $dev);

            return ['message' => $step === 'archive' ? "{$dev->name} archived." : "{$dev->name} restored."];
        });
    }

    public function computers(Request $request): JsonResponse
    {
        $this->allowed(DevicesScreen::canComputers());

        return response()->json((new DevicesScreen($request->user()))->computers($request->query()));
    }

    public function nameComputer(Request $request, string $computer, Recorder $recorder): JsonResponse
    {
        $this->allowed(DevicesScreen::canComputers());
        $c = Computer::find($computer) ?? abort(404);
        $d = $request->validate(['name' => ['required', 'string', 'max:80']], ['name.required' => 'Give it a name, e.g. Front desk PC.']);

        return $this->act(function (Actor $actor) use ($recorder, $c, $d) {
            $recorder->save($actor, $c->fill(['name' => trim($d['name'])]), 'computer.named');

            return ['message' => 'Named.'];
        });
    }
}
