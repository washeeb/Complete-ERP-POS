<?php

namespace App\Filament\Pages;

use App\Domain\Hr\HrService;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\Erp;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payslip;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Self-service: your leave balances and requests, and your payslips. */
class MyHr extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'HR & payroll';

    protected static ?string $navigationLabel = 'My leave & payslips';

    protected static ?string $title = 'My leave & payslips';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'my-hr';

    protected string $view = 'filament.pages.my-hr';

    public static function canAccess(): bool
    {
        $user = Erp::user();

        return $user && Erp::canAnywhere('leave.request') && Employee::where('user_id', $user->id)->exists() && config('erp.node.role') !== 'branch';
    }

    public function employee(): Employee
    {
        return Employee::where('user_id', Erp::user()->id)->firstOrFail();
    }

    public function balances(): array
    {
        $e = $this->employee();

        return LeaveType::where('is_active', true)->orderBy('name')->get()
            ->map(fn ($t) => ['name' => $t->name, 'left' => HrService::leaveBalance($e, $t, now()->year), 'allowance' => $t->days_per_year])->all();
    }

    public function requests()
    {
        return LeaveRequest::with('leaveType')->where('employee_id', $this->employee()->id)->orderByDesc('starts_on')->limit(20)->get();
    }

    public function payslips()
    {
        return Payslip::with('run')->where('employee_id', $this->employee()->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->orderByDesc('created_at')->limit(24)->get();
    }

    public function cancel(string $id): void
    {
        Erp::run(fn ($actor) => app(HrService::class)->cancelLeave($actor, LeaveRequest::where('employee_id', $this->employee()->id)->findOrFail($id)), 'Leave cancelled');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request')->label('Request leave')->icon(Heroicon::OutlinedPlus)
                ->schema(LeaveRequestResource::requestForm(chooseEmployee: false))
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(HrService::class)->requestLeave($actor, $this->employee(), LeaveType::findOrFail($data['leave_type_id']),
                    $data['starts_on'], $data['ends_on'], $data['reason'] ?? null, $data['document_path'] ?? null), 'Leave requested')),
        ];
    }
}
