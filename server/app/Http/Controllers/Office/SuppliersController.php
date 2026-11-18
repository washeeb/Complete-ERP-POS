<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\SuppliersScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Purchasing\SupplierOnboarding;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Security\ReconfirmNeeded;
use App\Support\Money;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Suppliers in the new back office (App\Domain\Office\SuppliersScreen): the list, one supplier, the form, and the
 * current screen's actions — approve, block, unblock, verify bank details, portal link, opening balance, archive,
 * restore, and the documents — each through the same service as the current screen. New or changed bank details ask
 * the person to confirm it is them (428 with the "Confirm it's you" address), as To-do does.
 */
class SuppliersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new SuppliersScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new SuppliersScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new SuppliersScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        abort_unless(SuppliersScreen::canManage(), 403, 'You cannot add or change suppliers.');

        return response()->json((new SuppliersScreen($request->user()))->form($id ? $this->find($id) : null));
    }

    /** Product units for "Products supplied". */
    public function units(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(SuppliersScreen::canManage(), 403);

        return response()->json(['results' => (new SuppliersScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $request->validate([
            'values' => ['required', 'array'], 'values.name' => ['required', 'string', 'max:255'], 'values.industry_id' => ['nullable', 'string'],
            'values.contact_name' => ['nullable', 'string', 'max:255'], 'values.phone' => ['nullable', 'string', 'max:30'], 'values.email' => ['nullable', 'email', 'max:255'],
            'values.tax_no' => ['nullable', 'string', 'max:40'], 'values.payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'values.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'values.return_days_before_expiry' => ['nullable', 'integer', 'min:0', 'max:730'],
            'values.address' => ['nullable', 'string', 'max:2000'], 'values.notes' => ['nullable', 'string', 'max:5000'],
            'values.supply_kind' => ['required', Rule::in(array_keys(Supplier::SUPPLY_KINDS))],
            'values.bank_name' => ['nullable', 'string', 'max:80'], 'values.bank_branch' => ['nullable', 'string', 'max:80'], 'values.bank_account_name' => ['nullable', 'string', 'max:120'],
            'values.bank_account_no' => ['nullable', 'string', 'max:40'], 'values.momo_number' => ['nullable', 'string', 'max:20'],
            'values.products' => ['nullable', 'array', 'max:500'], 'values.products.*.product_uom_id' => ['required', 'uuid'],
            'values.products.*.supplier_sku' => ['nullable', 'string', 'max:60'], 'values.products.*.last_cost' => ['nullable', 'numeric', 'min:0'],
            'values.products.*.min_order_qty' => ['nullable', 'numeric', 'min:0'], 'values.products.*.order_multiple' => ['nullable', 'numeric', 'min:0'],
            'values.products.*.is_preferred' => ['nullable', 'boolean'],
        ], ['values.name.required' => 'Enter the supplier name.', 'values.email.email' => 'That email address is not valid.',
            'values.products.*.product_uom_id.required' => 'Choose the product on each line of Products supplied (or remove the line).']);
        $supplier = $id ? $this->find($id) : new Supplier;
        $v = (array) $request->input('values');

        return $this->act($request, fn (Actor $actor) => ['id' => app(SupplierService::class)->save($actor, $supplier, (new SuppliersScreen($actor->user))->toDomain($v))->id]);
    }

    /** The current screen's buttons: approve, block, unblock, verify_bank, portal, opening, archive, restore. */
    public function action(Request $request, string $id): JsonResponse
    {
        $this->allowed();
        $a = $request->validate(['action' => ['required', Rule::in(['approve', 'block', 'unblock', 'verify_bank', 'portal', 'opening', 'archive', 'restore'])]])['action'];
        $d = $request->validate(match ($a) {
            'approve' => ['note' => ['nullable', 'string', 'max:255']],
            'block', 'unblock' => ['reason' => ['required', 'string', 'max:255']],
            'verify_bank' => ['how' => ['required', 'string', 'max:255']],
            'portal' => ['channel' => ['required', 'string'], 'to' => ['nullable', 'string', 'max:160'], 'renew' => ['nullable', 'boolean']],
            'opening' => ['amount' => ['required', 'numeric', 'gt:0'], 'as_of' => ['required', 'date'], 'due_on' => ['nullable', 'date'], 'reference' => ['nullable', 'string', 'max:60']],
            'archive' => ['reason' => ['required', 'string', 'min:3', 'max:255']],
            default => [],
        }, ['reason.required' => 'Say why.', 'how.required' => 'Say how you confirmed them.', 'amount.gt' => 'Enter an amount above zero.']);
        $s = $this->find($id);
        $onboarding = app(SupplierOnboarding::class);

        return $this->act($request, function (Actor $actor) use ($a, $d, $s, $onboarding) {
            $extra = [];
            if ($a === 'approve') {
                $onboarding->approve($actor, $s, $d['note'] ?? null);
            } elseif ($a === 'block') {
                $onboarding->block($actor, $s, $d['reason']);
            } elseif ($a === 'unblock') {
                $onboarding->unblock($actor, $s, $d['reason']);
            } elseif ($a === 'verify_bank') {
                $onboarding->verifyBank($actor, $s, $d['how']);
            } elseif ($a === 'portal') {
                abort_unless((new SuppliersScreen($actor->user))->show($s)['actions']['portal'] !== null, 403, 'You cannot send the portal link.');
                $portal = app(SupplierPortal::class);
                $extra['link'] = $portal->link($actor, $s, (bool) ($d['renew'] ?? false));
                if ($d['channel'] !== 'none') {
                    if (blank($d['to'] ?? null)) {
                        throw new DomainException('Enter where to send it.');
                    }
                    $extra['open'] = $portal->send($actor, $s->fresh(), $d['channel'], $d['to']);
                    $extra['sent'] = true;
                }
            } elseif ($a === 'opening') {
                abort_unless(SuppliersScreen::canOpening(), 403);
                app(SupplierPaymentService::class)->opening($actor, $s, Money::toMinor((string) $d['amount'], $s->currency_code ?: ($actor->user->tenant?->currency_code ?? 'GHS')),
                    $d['as_of'], $d['due_on'] ?? null, $d['reference'] ?? null);
            } elseif ($a === 'archive') {
                app(ArchiveService::class)->archive($actor, $s, $d['reason']);
            } else {
                app(ArchiveService::class)->restore($actor, $s);
            }

            return ['supplier' => (new SuppliersScreen($actor->user))->show($s->fresh())] + $extra;
        });
    }

    /** Add a document: the kind, its number, dates, and the scan or photo (PDF or picture). */
    public function addDocument(Request $request, string $id): JsonResponse
    {
        $this->allowed();
        $d = $request->validate([
            'kind' => ['required', Rule::in(array_keys(SupplierDocument::KINDS))], 'number' => ['nullable', 'string', 'max:80'],
            'issued_on' => ['nullable', 'date'], 'expires_on' => ['nullable', 'date'],
            'file' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'max:10240'],
        ], ['file.mimetypes' => 'Attach a PDF or a picture (JPEG, PNG or WebP).', 'file.max' => 'The file is larger than 10 MB.']);
        $s = $this->find($id);
        $file = $request->file('file');
        $path = $file?->store('supplier-documents', 'local');

        $answer = $this->act($request, function (Actor $actor) use ($s, $d, $path, $file) {
            app(SupplierOnboarding::class)->addDocument($actor, $s, $d, $path, $file?->getClientOriginalName());

            return ['supplier' => (new SuppliersScreen($actor->user))->show($s->fresh())];
        });
        if ($path && $answer->getStatusCode() !== 200) {
            Storage::disk('local')->delete($path); // not kept: nothing refers to it
        }

        return $answer;
    }

    /** "Checked against the original" or "Remove" (with the reason, kept in the audit log). */
    public function document(Request $request, string $id, string $doc): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['action' => ['required', Rule::in(['verify', 'remove'])], 'reason' => ['required_if:action,remove', 'nullable', 'string', 'max:255']],
            ['reason.required_if' => 'Say why it is removed.']);
        $s = $this->find($id);
        $document = SupplierDocument::where('supplier_id', $s->id)->findOrFail($doc);

        return $this->act($request, function (Actor $actor) use ($s, $d, $document) {
            $d['action'] === 'verify' ? app(SupplierOnboarding::class)->verifyDocument($actor, $document)
                : app(SupplierOnboarding::class)->removeDocument($actor, $document, $d['reason']);

            return ['supplier' => (new SuppliersScreen($actor->user))->show($s->fresh())];
        });
    }

    /** Open a document's file (only the people who see the supplier's documents). */
    public function documentFile(string $id, string $doc): StreamedResponse
    {
        $this->allowed();
        $s = $this->find($id);
        $document = SupplierDocument::where('supplier_id', $s->id)->findOrFail($doc);
        abort_unless($document->file_path && Storage::disk('local')->exists($document->file_path), 404, 'There is no file for this document.');

        return Storage::disk('local')->download($document->file_path, $document->file_name ?: basename($document->file_path));
    }

    private function find(string $id): Supplier
    {
        return SupplierResource::getEloquentQuery()->findOrFail($id);
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(SuppliersScreen::canView(), 403, 'You cannot see suppliers.');
    }
}
