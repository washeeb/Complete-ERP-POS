<?php

namespace App\Domain\Purchasing;

use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Phone;
use App\Domain\Messaging\Templates;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Http\Controllers\PrintController;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Sending an approved purchase order to the supplier from the app (owner request 2026-10-22): by e-mail with the order
 * as a PDF, by WhatsApp through the business's WhatsApp provider (a message with a download link), or by WhatsApp from
 * this computer (WhatsApp opens with the message ready; the person presses send). Every send is in the message log and
 * the order shows when, how and to whom it went. The download link works without signing in for 30 days, only for
 * that order's PDF.
 */
final class OrderSender
{
    public const CHANNELS = ['email' => 'E-mail (PDF attached)', 'whatsapp' => 'WhatsApp (sent by the system)', 'whatsapp_link' => 'WhatsApp from this computer'];

    public const LINK_DAYS = 30;

    public function __construct(private readonly Messenger $messenger, private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** The channels this business can use now: WhatsApp by the system needs a WhatsApp provider (Messages → Providers). */
    public function channels(string $tenantId): array
    {
        return $this->messenger->provider($tenantId, 'whatsapp') ? self::CHANNELS : array_diff_key(self::CHANNELS, ['whatsapp' => 1]);
    }

    /**
     * @return array{order: PurchaseOrder, open: ?string} open: the WhatsApp address to open on this computer (whatsapp_link)
     */
    public function send(Actor $actor, PurchaseOrder $po, string $channel, string $to, ?string $note = null): array
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'purchasing.manage', $po->warehouse)) {
            throw new AccessDenied;
        }
        if (! in_array($po->status, ['approved', 'partially_received'], true)) {
            throw new DomainException("Purchase order {$po->number} is ".(PurchaseOrder::STATUSES[$po->status] ?? $po->status).'; only an approved order is sent to the supplier.');
        }
        if (! array_key_exists($channel, $this->channels($po->tenant_id))) {
            throw new DomainException('Choose e-mail or WhatsApp.');
        }
        $to = trim($to);
        if ($channel === 'email' ? ! filter_var($to, FILTER_VALIDATE_EMAIL) : ! Phone::looksValid($to)) {
            throw new DomainException($channel === 'email' ? 'That e-mail address does not look right.' : 'That phone number does not look right.');
        }
        $supplier = Supplier::findOrFail($po->supplier_id);
        $path = $this->pdf($po);
        $link = URL::temporarySignedRoute('purchase-order.pdf', now()->addDays(self::LINK_DAYS), ['po' => $po->id]);
        $vars = ['company' => PrintController::companyOf($po->tenant_id, $po->branch_id)['name'], 'supplier' => $supplier->name, 'number' => $po->number,
            'total' => Money::display((int) $po->total_minor, $po->currency_code), 'deliver_by' => $po->expected_on ? ' Please deliver by '.$po->expected_on->format('j M Y').'.' : '',
            'note' => trim((string) $note), 'link' => $link];
        $open = null;
        if ($channel === 'whatsapp_link') {
            $template = Templates::find($po->tenant_id, 'purchase_order', 'whatsapp') ?? throw new DomainException('The purchase order wording is missing (Messages → Templates).');
            [, $body] = Templates::render($template, $vars);
            $open = 'https://wa.me/'.ltrim(Phone::international($to), '+').'?text='.rawurlencode(trim($body."\n\n".$vars['note']));
        } else {
            $template = Templates::find($po->tenant_id, 'purchase_order', $channel);
            if (! $template?->is_active) {
                throw new DomainException('Purchase orders by '.($channel === 'email' ? 'e-mail' : 'WhatsApp').' are switched off (Messages → Templates).');
            }
            $this->messenger->fromTemplate($po->tenant_id, $channel, $to, $template, $vars, $po->number, $actor->name,
                attachmentPath: $channel === 'email' ? $path : null, attachmentName: $channel === 'email' ? "{$po->number}.pdf" : null);
        }
        $po->forceFill(['sent_at' => now(), 'sent_via' => $channel, 'sent_to' => mb_substr($to, 0, 160), 'sent_by_name' => mb_substr($actor->name, 0, 120)]);
        $order = $this->recorder->transaction(fn () => $this->recorder->save($actor, $po, 'purchase_order.sent', self::CHANNELS[$channel].' to '.$to));

        return ['order' => $order, 'open' => $open];
    }

    /** The order as a PDF on the private disk (made again on each send, so it shows the order as it is now). */
    public function pdf(PurchaseOrder $po): string
    {
        $html = view('print.purchase-order', ['doc' => $po->load('lines'), 'company' => PrintController::companyOf($po->tenant_id, $po->branch_id),
            'supplier' => Supplier::find($po->supplier_id), 'warehouse' => Warehouse::find($po->warehouse_id), 'pdf' => true, 'tenantId' => $po->tenant_id])->render();
        $options = new Options;
        $options->setIsRemoteEnabled(false); // never fetch anything from the network while rendering
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $path = "purchase-orders/{$po->id}.pdf";
        Storage::disk('local')->put($path, $pdf->output());
        $po->forceFill(['pdf_path' => $path]);

        return $path;
    }

    /** For the signed download link: the PDF of an order that is still current. */
    public static function download(string $id): ?string
    {
        $po = PurchaseOrder::withoutGlobalScopes()->find($id);
        if (! $po || ! $po->pdf_path || in_array($po->status, ['cancelled', 'draft'], true) || ! Storage::disk('local')->exists($po->pdf_path)) {
            return null;
        }

        return $po->pdf_path;
    }
}
