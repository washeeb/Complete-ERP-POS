<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pos\TillApprovals;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\TillApproval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The till asks a manager who is not at the till, waits for the answer, or lets a manager at the till approve there
 * (App\Domain\Pos\TillApprovals). The till then sends the request id as its approval, once.
 */
class TillApprovalController extends Controller
{
    public function ask(Request $request, TillApprovals $approvals): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(TillApproval::KINDS))],
            'amount_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'limit_amount' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'summary' => ['required', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'array', 'max:50'],
            'details.*.name' => ['required', 'string', 'max:255'],
            'details.*.qty' => ['nullable', 'string', 'max:20'],
            'details.*.amount_minor' => ['nullable', 'integer'],
        ]);
        $r = $approvals->ask($this->device($request), $request->user(), $data['kind'], (int) ($data['amount_minor'] ?? 0), (int) ($data['limit_amount'] ?? 0),
            $data['summary'], $data['details'] ?? null, $data['reason'] ?? null);

        return response()->json(['approval' => $this->show($r)], 201);
    }

    public function status(Request $request, string $id, TillApprovals $approvals): JsonResponse
    {
        return response()->json(['approval' => $this->show($approvals->status($this->device($request), $this->find($request, $id)))]);
    }

    /** A manager at the till: their staff card (or username) and PIN. */
    public function here(Request $request, string $id, TillApprovals $approvals): JsonResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:200'], 'pin' => ['required', 'string', 'max:8']]);

        return response()->json(['approval' => $this->show($approvals->approveHere($this->device($request), $this->find($request, $id), $data))]);
    }

    public function cancel(Request $request, string $id, TillApprovals $approvals): JsonResponse
    {
        return response()->json(['approval' => $this->show($approvals->cancel($this->device($request), $this->find($request, $id)))]);
    }

    /** Used for things that do not go to the server by themselves (removing items from a sale): the approval is spent now. */
    public function use(Request $request, string $id, TillApprovals $approvals): JsonResponse
    {
        $device = $this->device($request);
        $r = $this->find($request, $id);
        $approver = $approvals->consume($device, $r->id, $r->permission, $r->limit_key, 0, $request->user()->id);

        return response()->json(['approved_by' => $approver->name]);
    }

    private function find(Request $request, string $id): TillApproval
    {
        return TillApproval::where('device_id', $this->device($request)->id)->findOrFail($id);
    }

    private function show(TillApproval $r): array
    {
        return ['id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'decided_by' => $r->decided_by_name, 'note' => $r->decision_note,
            'expires_at' => $r->expires_at->toIso8601String()];
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }
}
