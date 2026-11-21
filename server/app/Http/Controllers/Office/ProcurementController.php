<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ProcurementScreen;
use App\Domain\Office\SuppliersScreen;
use App\Domain\Purchasing\AgreementService;
use App\Domain\Purchasing\PurchaseRequestService;
use App\Domain\Purchasing\RfqService;
use App\Domain\Support\Actor;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Rfqs\RfqResource;
use App\Filament\Support\Fields;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The rest of Purchasing in the new back office (App\Domain\Office\ProcurementScreen): Purchase requests, Requests for
 * quotation and Contracts and blanket orders — the lists, one of each, the forms and the current pages' buttons, each
 * through the same service. A record is found only within the resources' queries (the person's branches).
 */
class ProcurementController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): ProcurementScreen
    {
        $this->allowed($can);

        return new ProcurementScreen($r->user());
    }

    /** Lines of a request or agreement: a product unit, or a service with its kind of spending and description. */
    private static function spendLines(array $lines, array $extra): array
    {
        return array_map(function ($l) use ($extra) {
            $service = ($l['kind'] ?? 'stock') === 'service';
            $row = ['product_uom_id' => $service ? null : (($l['product_uom_id'] ?? '') ?: null), 'account_id' => $service ? (($l['account_id'] ?? '') ?: null) : null,
                'description' => $service ? trim((string) ($l['description'] ?? '')) : null, 'uom_code' => $service ? (trim((string) ($l['uom_code'] ?? '')) ?: 'EA') : null];
            foreach ($extra as $k) {
                $row[$k] = ($l[$k] ?? '') === '' ? null : $l[$k];
            }

            return $row;
        }, array_values($lines));
    }

    private const LINE_RULES = ['lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.kind' => ['required', 'in:stock,service'],
        'lines.*.product_uom_id' => ['nullable', 'uuid'], 'lines.*.account_id' => ['nullable', 'uuid'], 'lines.*.description' => ['nullable', 'string', 'max:160'],
        'lines.*.uom_code' => ['nullable', 'string', 'max:10'], 'lines.*.note' => ['nullable', 'string', 'max:255']];

    private const LINE_MESSAGES = ['lines.required' => 'Add at least one line.', 'lines.*.qty.*' => 'Enter a quantity above zero on every line.',
        'lines.*.unit_cost.*' => 'Enter the agreed price on every line.'];

    /** Buying units of any active product, for a line (as the current forms). */
    public function units(Request $request): JsonResponse
    {
        $this->allowed(ProcurementScreen::canRequests() || ProcurementScreen::canRfqs() || ProcurementScreen::canAgreements());

        return response()->json(['results' => (new SuppliersScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function available(Request $request): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canRequests() || ProcurementScreen::canRfqs());
        $d = $request->validate(['unit' => ['required', 'uuid'], 'warehouse' => ['required', 'uuid']]);

        return response()->json(['available' => $screen->available($d['unit'], $d['warehouse'])]);
    }

    // -------------------------------------------------------------------------------------------- Purchase requests

    public function requests(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ProcurementScreen::canRequests())->requests($request->query()));
    }

    public function requestShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canRequests());

        return response()->json($screen->request($screen->requestsQuery()->findOrFail($id)));
    }

    public function requestForm(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, PurchaseRequestResource::canCreate())->requestForm());
    }

    public function requestSave(Request $request, PurchaseRequestService $svc): JsonResponse
    {
        $this->screen($request, PurchaseRequestResource::canCreate());
        $d = $request->validate(['warehouse_id' => ['required', Rule::in(array_keys(Fields::warehouses('purchasing.request')))], 'needed_by' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:2000'], 'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.est_unit_cost' => ['nullable', 'numeric', 'min:0'], 'lines.*.department_id' => ['nullable', 'uuid']] + self::LINE_RULES,
            ['warehouse_id.*' => 'Choose a store you may ask for.'] + self::LINE_MESSAGES);

        return $this->act(fn (Actor $actor) => ['id' => $svc->request($actor, ['warehouse_id' => $d['warehouse_id'], 'needed_by' => $d['needed_by'] ?? null,
            'department_id' => ($d['department_id'] ?? '') ?: null, 'note' => ($d['note'] ?? '') ?: null,
            'lines' => self::spendLines($d['lines'], ['qty', 'est_unit_cost', 'note', 'department_id'])])->id]);
    }

    /** order (supplier) · quotes (suppliers, closes_at, terms) · reject (reason) · cancel (reason). */
    public function requestAction(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canRequests());
        /** @var PurchaseRequest $r */
        $r = $screen->requestsQuery()->findOrFail($id);
        $a = $request->validate(['action' => ['required', Rule::in(['order', 'quotes', 'reject', 'cancel'])]])['action'];
        $d = $request->validate(match ($a) {
            'order' => ['supplier_id' => ['required', 'uuid']],
            'quotes' => ['supplier_ids' => ['required', 'array', 'min:1'], 'supplier_ids.*' => ['uuid'], 'closes_at' => ['required', 'date', 'after:now'], 'terms' => ['nullable', 'string', 'max:2000']],
            default => ['reason' => ['required', 'string', 'max:200']],
        }, ['supplier_id.*' => 'Choose the supplier.', 'supplier_ids.*' => 'Choose the suppliers to ask.', 'closes_at.*' => 'Choose when quotes close (in the future).', 'reason.*' => 'Say why.']);

        return $this->act(function (Actor $actor) use ($a, $d, $r, $screen) {
            $svc = app(PurchaseRequestService::class);

            return match ($a) {
                'order' => ['open' => '/office/purchase-orders/'.$svc->order($actor, $r, Supplier::findOrFail($d['supplier_id']))->id.'/edit', 'message' => 'Draft purchase order made.'],
                'quotes' => ['open' => '/office/rfqs/'.app(RfqService::class)->fromRequest($actor, $r, $d['supplier_ids'], $d['closes_at'], $d['terms'] ?? null)->id,
                    'message' => 'Request for quotation made: now send it to each supplier.'],
                'reject' => ['message' => $svc->reject($actor, $r, $d['reason']) ? 'Rejected.' : '', 'request' => $screen->request($r->fresh())],
                default => ['message' => $svc->cancel($actor, $r, $d['reason']) ? 'Cancelled.' : '', 'request' => $screen->request($r->fresh())],
            };
        });
    }

    // -------------------------------------------------------------------------------------------- Requests for quotation

    public function rfqs(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ProcurementScreen::canRfqs())->rfqs($request->query()));
    }

    private function findRfq(ProcurementScreen $screen, string $id): Rfq
    {
        return $screen->rfqsQuery()->findOrFail($id);
    }

    public function rfqShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canRfqs());

        return response()->json($screen->rfq($this->findRfq($screen, $id)));
    }

    public function rfqForm(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, RfqResource::canCreate())->rfqForm());
    }

    public function rfqSave(Request $request, RfqService $svc): JsonResponse
    {
        $this->screen($request, RfqResource::canCreate());
        $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'warehouse_id' => ['required', Rule::in(array_keys(Fields::warehouses('purchasing.manage')))],
            'closes_at' => ['required', 'date', 'after:now'], 'needed_by' => ['nullable', 'date_format:Y-m-d'], 'supplier_ids' => ['required', 'array', 'min:1'], 'supplier_ids.*' => ['uuid'],
            'terms' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.product_uom_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'], 'lines.*.note' => ['nullable', 'string', 'max:255']],
            ['title.*' => 'Give it a title.', 'warehouse_id.*' => 'Choose where it is delivered.', 'closes_at.*' => 'Choose when quotes close (in the future).',
                'supplier_ids.*' => 'Choose the suppliers to ask.', 'lines.required' => 'Add at least one item.', 'lines.*.product_uom_id.*' => 'Choose the product on every line.',
                'lines.*.qty.*' => 'Enter a quantity above zero on every line.']);

        return $this->act(fn (Actor $actor) => ['id' => $svc->create($actor, $d)->id]);
    }

    /** send · invite · quote · close · award · cancel, as the current page's buttons. */
    public function rfqAction(Request $request, string $id, RfqService $svc): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canRfqs());
        $rfq = $this->findRfq($screen, $id);
        $a = $request->validate(['action' => ['required', Rule::in(['send', 'invite', 'quote', 'close', 'award', 'cancel'])]])['action'];
        $d = $request->validate(match ($a) {
            'send' => ['invitation' => ['required', 'uuid'], 'channel' => ['required', 'string'], 'to' => ['required', 'string', 'max:160'], 'note' => ['nullable', 'string', 'max:500']],
            'invite' => ['supplier_id' => ['required', 'uuid']],
            'quote' => ['invitation' => ['required', 'uuid'], 'from' => ['nullable', 'string', 'max:100'], 'lines' => ['required', 'array'], 'lines.*.price' => ['nullable', 'numeric', 'min:0'],
                'lines.*.qty' => ['nullable', 'numeric', 'min:0'], 'delivery_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
                'valid_until' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:500']],
            'award' => ['awards' => ['required', 'array'], 'awards.*' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:500']],
            'cancel' => ['reason' => ['required', 'string', 'max:255']],
            default => [],
        }, ['invitation.*' => 'Choose the supplier.', 'to.*' => 'Enter where to send it.', 'supplier_id.*' => 'Choose the supplier.', 'reason.*' => 'Say why.']);
        $inv = in_array($a, ['send', 'quote'], true) ? RfqSupplier::where('rfq_id', $rfq->id)->findOrFail($d['invitation']) : null;

        return $this->act(function (Actor $actor) use ($a, $d, $rfq, $inv, $svc, $screen) {
            $extra = [];
            $message = match ($a) {
                'send' => ($extra['open'] = $svc->send($actor, $inv, $d['channel'], $d['to'], $d['note'] ?? null)) ? 'Ready in WhatsApp: open it and press send.' : 'Sent. See Messages → Message log if it does not arrive.',
                'invite' => ($svc->invite($actor, $rfq, Supplier::findOrFail($d['supplier_id'])) ? 'Added: now send them the request.' : ''),
                'quote' => ($svc->quote($inv, ['lines' => collect($d['lines'])->map(fn ($l) => ['price' => (string) ($l['price'] ?? ''), 'qty' => (string) ($l['qty'] ?? '')])->all()]
                    + array_intersect_key($d, array_flip(['delivery_days', 'payment_days', 'valid_until', 'note'])), trim((string) ($d['from'] ?? '')) ?: $actor->name, $actor) ? 'Quote entered.' : ''),
                'close' => ($svc->close($actor, $rfq) ? 'Closed: the prices are open.' : ''),
                'award' => (function () use ($actor, $rfq, $d, $svc, &$extra) {
                    $pos = $svc->award($actor, $rfq, $d['awards'], $d['note'] ?? null);
                    $extra['open'] = count($pos) === 1 ? "/office/purchase-orders/{$pos[0]->id}/edit" : '/office/purchase-orders';

                    return 'Awarded: '.implode(', ', array_map(fn ($p) => $p->number, $pos)).'. Check and submit the draft order(s) for approval.';
                })(),
                default => ($svc->cancel($actor, $rfq, $d['reason']) ? 'Cancelled.' : ''),
            };

            return ['message' => $message, 'rfq' => $screen->rfq($rfq->fresh())] + $extra;
        });
    }

    // -------------------------------------------------------------------------------------------- Contracts and blanket orders

    public function agreements(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ProcurementScreen::canAgreements())->agreements($request->query()));
    }

    private function findAgreement(ProcurementScreen $screen, string $id): PurchaseAgreement
    {
        return $screen->agreementsQuery()->findOrFail($id);
    }

    public function agreementShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canAgreements());

        return response()->json($screen->agreement($this->findAgreement($screen, $id)));
    }

    public function agreementForm(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canAgreements());
        $a = $id ? $this->findAgreement($screen, $id) : null;
        abort_unless($a ? PurchaseAgreementResource::canEdit($a) : PurchaseAgreementResource::canCreate(), 403, $a ? 'Only a draft can be changed.' : 'You cannot make agreements.');

        return response()->json($screen->agreementForm($a));
    }

    /** Save a draft (with the signed agreement as a file, optional); sent as a form for the file, the lines as JSON. */
    public function agreementSave(Request $request, AgreementService $svc, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canAgreements());
        $a = $id ? $this->findAgreement($screen, $id) : new PurchaseAgreement;
        abort_unless($id ? PurchaseAgreementResource::canEdit($a) : PurchaseAgreementResource::canCreate(), 403, $id ? 'Only a draft can be changed.' : 'You cannot make agreements.');
        $request->merge(['lines' => json_decode((string) $request->input('lines', '[]'), true) ?: []]);
        $d = $request->validate(['supplier_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(array_keys(PurchaseAgreement::KINDS))], 'title' => ['required', 'string', 'max:160'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'value_cap' => ['nullable', 'numeric', 'min:0.01'],
            'terms' => ['nullable', 'string', 'max:2000'], 'file' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:10240'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0.0001'], 'lines.*.max_qty' => ['nullable', 'numeric', 'min:0.001']] + self::LINE_RULES,
            ['supplier_id.*' => 'Choose the supplier.', 'title.*' => 'Give it a title.', 'ends_on.after_or_equal' => 'It ends before it starts.',
                'file.mimetypes' => 'Attach a PDF or a photo.', 'file.max' => 'The file is larger than 10 MB.'] + self::LINE_MESSAGES);
        $file = $request->file('file');
        $path = $file?->store('agreements', 'local');

        return $this->act(fn (Actor $actor) => ['id' => $svc->save($actor, $a, ['supplier_id' => $d['supplier_id'], 'kind' => $d['kind'], 'title' => $d['title'],
            'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'], 'value_cap' => ($d['value_cap'] ?? '') === '' ? null : $d['value_cap'], 'terms' => ($d['terms'] ?? '') ?: null,
            'lines' => self::spendLines($d['lines'], ['unit_cost', 'max_qty', 'note'])]
            + ($path ? ['file_path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255)] : []))->id]);
    }

    /** activate · release (warehouse, expected_on, qtys) · close (reason). */
    public function agreementAction(Request $request, string $id, AgreementService $svc): JsonResponse
    {
        $screen = $this->screen($request, ProcurementScreen::canAgreements());
        $agr = $this->findAgreement($screen, $id);
        $act = $request->validate(['action' => ['required', Rule::in(['activate', 'release', 'close'])]])['action'];
        $d = $request->validate(match ($act) {
            'release' => ['warehouse_id' => ['required', Rule::in(array_keys(Fields::warehouses('purchasing.manage')))], 'expected_on' => ['nullable', 'date_format:Y-m-d'],
                'qtys' => ['required', 'array'], 'qtys.*' => ['nullable', 'numeric', 'min:0']],
            'close' => ['reason' => ['required', 'string', 'max:255']],
            default => [],
        }, ['warehouse_id.*' => 'Choose where it is delivered.', 'reason.*' => 'Say why.']);

        return $this->act(fn (Actor $actor) => match ($act) {
            'activate' => ['message' => $svc->activate($actor, $agr) ? 'In force.' : '', 'agreement' => $screen->agreement($agr->fresh())],
            'release' => ['open' => '/office/purchase-orders/'.$svc->release($actor, $agr, Warehouse::findOrFail($d['warehouse_id']),
                array_map(fn ($q) => (string) ($q ?? ''), $d['qtys']), $d['expected_on'] ?? null)->id.'/edit', 'message' => 'Draft purchase order made.'],
            default => ['message' => $svc->close($actor, $agr, $d['reason']) ? 'Closed.' : '', 'agreement' => $screen->agreement($agr->fresh())],
        });
    }
}
