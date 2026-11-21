<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\PharmacyScreen;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Pharmacy\RefillReminders;
use App\Domain\Support\Actor;
use App\Filament\Pages\ControlledDrugRegister;
use App\Filament\Resources\DrugInteractions\DrugInteractionResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\RefillReminders\RefillReminderResource;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\DrugInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pharmacy in the new back office (App\Domain\Office\PharmacyScreen): prescriptions, refill reminders, drug
 * interactions and the controlled-drug register, through PharmacyService and RefillReminders as the current screens.
 */
class PharmacyController extends Controller
{
    use ActsOnAccess;

    public function prescriptions(Request $request): JsonResponse
    {
        $this->allowed(PrescriptionResource::canViewAny());

        return response()->json((new PharmacyScreen($request->user()))->prescriptions($request->query()));
    }

    public function prescription(Request $request, string $prescription): JsonResponse
    {
        $this->allowed(PrescriptionResource::canViewAny());
        $r = PrescriptionResource::getEloquentQuery()->find($prescription) ?? abort(404);

        return response()->json((new PharmacyScreen($request->user()))->prescription($r));
    }

    public function reminders(Request $request): JsonResponse
    {
        $this->allowed(RefillReminderResource::canViewAny());

        return response()->json((new PharmacyScreen($request->user()))->reminders($request->query()));
    }

    public function reminder(Request $request, string $reminder, string $step, RefillReminders $reminders): JsonResponse
    {
        $this->allowed(RefillReminderResource::canViewAny());
        $r = RefillReminderResource::getEloquentQuery()->find($reminder) ?? abort(404);
        abort_unless(Erp::can('customers.manage', $r->branch), 403, 'You cannot send or cancel reminders for this branch.');

        return $this->act(function (Actor $actor) use ($reminders, $r, $step) {
            $step === 'send' ? $reminders->sendNow($actor, $r) : $reminders->cancel($actor, $r);

            return ['message' => $step === 'send' ? 'Reminder sent.' : 'Reminder cancelled.'];
        });
    }

    public function interactions(Request $request): JsonResponse
    {
        $this->allowed(DrugInteractionResource::canViewAny());

        return response()->json((new PharmacyScreen($request->user()))->interactions($request->query()));
    }

    public function saveInteraction(Request $request, PharmacyService $pharmacy, ?string $interaction = null): JsonResponse
    {
        $this->allowed(DrugInteractionResource::canViewAny());
        $rule = $interaction ? (DrugInteraction::find($interaction) ?? abort(404)) : new DrugInteraction;
        abort_unless($interaction ? DrugInteractionResource::canEdit($rule) : DrugInteractionResource::canCreate(), 403, 'You cannot change the interaction table.');
        $d = $request->validate(['ingredient_a' => ['required', 'string', 'max:120'], 'ingredient_b' => ['required', 'string', 'max:120'],
            'severity' => ['required', 'string', 'in:'.implode(',', array_keys(DrugInteraction::SEVERITIES))], 'note' => ['required', 'string', 'max:255']],
            ['ingredient_a.*' => 'Enter the ingredient.', 'ingredient_b.*' => 'Enter what it interacts with.', 'severity.*' => 'Choose how serious it is.',
                'note.*' => 'Say what the pharmacist should do.']);

        return $this->act(fn (Actor $actor) => ['id' => $pharmacy->saveInteraction($actor, $rule, $d)->id,
            'message' => $interaction ? 'Interaction saved.' : 'Interaction added. Tills pick it up at their next sync.']);
    }

    public function starter(Request $request, PharmacyService $pharmacy): JsonResponse
    {
        $this->allowed(DrugInteractionResource::canCreate());

        return $this->act(function (Actor $actor) use ($pharmacy) {
            $n = $pharmacy->addStarter($actor);

            return ['message' => $n ? "{$n} interaction(s) added." : 'Every pair of the starter list is already in the table.'];
        });
    }

    public function register(Request $request): JsonResponse
    {
        $this->allowed(ControlledDrugRegister::canAccess());

        return response()->json((new PharmacyScreen($request->user()))->register($request->query()));
    }
}
