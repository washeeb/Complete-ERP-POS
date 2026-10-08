<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Modules\ModuleService;
use App\Domain\Services\AppointmentService;
use App\Domain\Services\PassService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Services at the till: the day's appointments by staff member, booking and changing them, and a customer's passes. */
class ServicesController extends Controller
{
    public function __construct(private readonly AppointmentService $appointments) {}

    public function day(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? now()->setTimezone($this->tz($branch))->toDateString();

        return response()->json(['date' => $date, 'staff' => AppointmentService::staff($branch->id)->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'job_title' => $e->job_title])->values(),
            'appointments' => array_map(fn ($a) => self::json($a), AppointmentService::day($branch->id, $date, $this->tz($branch)))]);
    }

    public function book(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate(['employee_id' => [$id ? 'nullable' : 'required', 'uuid'], 'product_id' => [$id ? 'nullable' : 'required', 'uuid'],
            'starts_at' => [$id ? 'nullable' : 'required', 'date'], 'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'], 'customer_id' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:255']]);
        $a = $id ? $this->find($request, $id) : new Appointment;
        $a = $this->appointments->book($this->actor($request), $a, array_filter($data, fn ($v) => $v !== null) + ['branch_id' => $this->branch($request)->id]);

        return response()->json(['appointment' => self::json($a->load('employee:id,name', 'product:id,name'))], $id ? 200 : 201);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'in:booked,arrived,no_show,cancelled'], 'reason' => ['nullable', 'string', 'max:255']]);
        $a = $this->appointments->setStatus($this->actor($request), $this->find($request, $id), $data['status'], $data['reason'] ?? null);

        return response()->json(['appointment' => self::json($a->load('employee:id,name', 'product:id,name'))]);
    }

    /** A customer's passes that can still be used, with sessions left per item. */
    public function passes(Request $request): JsonResponse
    {
        $this->branch($request);
        $customerId = $request->validate(['customer_id' => ['required', 'uuid']])['customer_id'];

        return response()->json(['passes' => array_map(fn ($x) => ['id' => $x['pass']->id, 'number' => $x['pass']->number, 'name' => $x['pass']->name,
            'expires_on' => $x['pass']->expires_on?->toDateString(),
            'items' => array_map(fn ($it, $i) => $it + ['remaining' => $x['remaining'][$i]], $x['pass']->items, array_keys($x['pass']->items))],
            PassService::forCustomer($customerId))]);
    }

    public static function json(Appointment $a): array
    {
        return ['id' => $a->id, 'number' => $a->number, 'customer_id' => $a->customer_id, 'customer_name' => $a->customer_name, 'phone' => $a->phone,
            'employee_id' => $a->employee_id, 'employee_name' => $a->employee?->name, 'product_id' => $a->product_id, 'service' => $a->product?->name,
            'starts_at' => $a->starts_at->toIso8601String(), 'ends_at' => $a->ends_at->toIso8601String(), 'status' => $a->status,
            'status_label' => Appointment::STATUSES[$a->status] ?? $a->status, 'note' => $a->note];
    }

    private function find(Request $request, string $id): Appointment
    {
        return Appointment::where('branch_id', $this->branch($request)->id)->findOrFail($id);
    }

    private function branch(Request $request): Branch
    {
        $branch = Branch::findOrFail($this->device($request)->branch_id);
        if (! app(ModuleService::class)->isEnabled($branch, 'services')) {
            throw new DomainException('The Services module is not on at this branch.', 'module_disabled');
        }

        return $branch;
    }

    private function tz(Branch $branch): string
    {
        return $branch->timezone ?: (Tenant::find($branch->tenant_id)?->timezone ?: 'UTC');
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private function actor(Request $request): Actor
    {
        return Actor::user($request->user(), 'pos', $this->device($request)->id);
    }
}
