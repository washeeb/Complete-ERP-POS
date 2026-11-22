<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\CounterScreen;
use App\Domain\Pharmacy\HealthReadings;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\HealthReadingsPage;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\HealthReading;
use App\Support\Exports\Exporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Health checks and Scale items in the new back office (App\Domain\Office\CounterScreen): the readings list, Record a
 * reading (HealthReadings::record), Add a fee service (as the current page) and the scale list with its export.
 */
class CounterController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): CounterScreen
    {
        $this->allowed($can);

        return new CounterScreen($r->user());
    }

    public function readings(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CounterScreen::canHealth())->readings($request->query()));
    }

    public function readingForm(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CounterScreen::canHealth())->readingForm());
    }

    public function patients(Request $request): JsonResponse
    {
        $screen = $this->screen($request, CounterScreen::canHealth());

        return response()->json(['results' => $screen->patients(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function record(Request $request, HealthReadings $svc): JsonResponse
    {
        $this->screen($request, CounterScreen::canHealth());
        $d = $request->validate(['customer_id' => ['nullable', 'uuid'], 'branch_id' => ['required', Rule::in(array_keys(FinanceUi::branches()))],
            'kind' => ['required', Rule::in(array_keys(HealthReading::KINDS))], 'systolic' => ['nullable', 'integer'], 'diastolic' => ['nullable', 'integer'],
            'pulse' => ['nullable', 'integer', 'min:0', 'max:300'], 'value' => ['nullable', 'numeric'], 'result' => ['nullable', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:255']],
            ['branch_id.*' => 'Choose the branch.', 'kind.*' => 'Choose what was measured.']);

        return $this->act(fn (Actor $actor) => ['id' => $svc->record($actor, $d)->id, 'message' => 'Reading saved.']);
    }

    /** A fee service sold at the till like a product, with no stock; with a reading, the till asks for it. */
    public function addService(Request $request): JsonResponse
    {
        $this->screen($request, CounterScreen::canAddService());
        $d = $request->validate(['name' => ['required', 'string', 'max:160'], 'price' => ['required', 'numeric', 'min:0'], 'reading_kind' => ['nullable', Rule::in(array_keys(HealthReading::KINDS))],
            'tax_group_id' => ['required', 'uuid']], ['name.*' => 'Name the service.', 'price.*' => 'Enter the fee.', 'tax_group_id.*' => 'Choose the tax.']);

        return $this->act(fn (Actor $actor) => ['id' => HealthReadingsPage::addService($actor, $d)->id, 'message' => 'Service added: find it at the till by name.']);
    }

    public function scale(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CounterScreen::canScale())->scaleItems());
    }

    public function scaleExport(Request $request, Exporter $exporter): StreamedResponse|JsonResponse
    {
        $x = $this->screen($request, CounterScreen::canScale())->scaleSheets();
        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 422, 'Choose Excel, CSV or PDF.');
        $actor = Actor::user($request->user(), 'web');
        try {
            return match ($format) {
                'pdf' => $exporter->pdf($actor, $x['title'], $x['sheets'], $x['filters']),
                'xlsx' => $exporter->xlsx($actor, $x['title'], $x['sheets'], $x['filters']),
                default => $exporter->csv($actor, $x['title'], $x['sheets'], $x['filters']),
            };
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }
}
