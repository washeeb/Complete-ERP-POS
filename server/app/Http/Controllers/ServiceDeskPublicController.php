<?php

namespace App\Http\Controllers;

use App\Domain\Service\ServiceDesk;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Support\Settings;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The service desk's doors to the outside (paid add-on "service"):
 *  - /help/{company}: the web form customers report a problem on (when the company switched it on);
 *  - /r/{ticket}: "How did we do?" from the link sent with a resolution (signed);
 *  - /api/service/email/{key}: e-mails forwarded by the company's mail provider (Mailgun, SendGrid, Postmark…).
 */
class ServiceDeskPublicController extends Controller
{
    public function form(string $company)
    {
        $tenant = $this->tenantWithForm($company);

        return view('service-help', ['company' => $tenant->name, 'done' => null]);
    }

    public function submit(Request $request, string $company)
    {
        $tenant = $this->tenantWithForm($company);
        if (filled($request->input('website'))) { // a field people do not see: filled in by robots
            return view('service-help', ['company' => $tenant->name, 'done' => 'TCK']);
        }
        $data = $request->validate([
            'contact_name' => ['required', 'string', 'max:120'], 'contact_phone' => ['nullable', 'string', 'max:30', 'required_without:contact_email'],
            'contact_email' => ['nullable', 'email', 'max:255'], 'subject' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:5000'],
            'kind' => ['nullable', 'in:support,complaint,return,warranty'], 'receipt_no' => ['nullable', 'string', 'max:40'],
        ]);
        try {
            $t = app(ServiceDesk::class)->fromWeb($tenant, $data);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        return view('service-help', ['company' => $tenant->name, 'done' => $t->number]);
    }

    public function rateForm(string $ticket)
    {
        return view('service-rate', ['ticket' => $this->ticket($ticket), 'done' => false]);
    }

    public function rate(Request $request, string $ticket)
    {
        $t = $this->ticket($ticket);
        app(ServiceDesk::class)->rate($t, (int) $request->input('rating'));

        return view('service-rate', ['ticket' => $t, 'done' => true]);
    }

    /** No one is signed in here: the signed link names the ticket, and so its company. */
    private function ticket(string $id): Ticket
    {
        $t = Ticket::withoutGlobalScopes()->findOrFail($id);
        app(TenantContext::class)->set($t->tenant_id);

        return $t;
    }

    /** An e-mail from the provider's inbound forwarding (form or JSON; the usual field names of the big providers). */
    public function email(Request $request, string $key): Response
    {
        $tenant = Tenant::all()->first(function (Tenant $t) use ($key) {
            $own = (string) app(Settings::class)->get('service.email_key', $t->id);

            return strlen($own) >= 24 && hash_equals($own, $key);
        });
        if (! $tenant || ! ServiceDesk::enabled()) {
            return response('', 404);
        }
        $from = (string) ($request->input('FromFull.Email') ?? $request->input('sender') ?? $request->input('from') ?? $request->input('From') ?? '');
        $name = $request->input('FromName') ?? $request->input('FromFull.Name');
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>/', $from, $m)) {
            [$name, $from] = [trim($m[1]) ?: $name, $m[2]];
        }
        $subject = (string) ($request->input('subject') ?? $request->input('Subject') ?? '');
        $body = (string) ($request->input('stripped-text') ?? $request->input('text') ?? $request->input('body-plain') ?? $request->input('TextBody') ?? $request->input('plain') ?? '');
        if (! filter_var(trim($from), FILTER_VALIDATE_EMAIL) || trim($body.$subject) === '') {
            return response('', 422);
        }
        app(ServiceDesk::class)->fromEmail($tenant, trim($from), $name ? mb_substr((string) $name, 0, 120) : null, mb_substr($subject, 0, 255), mb_substr($body, 0, 20000));

        return response('', 204);
    }

    private function tenantWithForm(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->first();
        abort_unless($tenant && ServiceDesk::enabled() && app(Settings::class)->get('service.web_form', $tenant->id), 404);
        app(TenantContext::class)->set($tenant->id);

        return $tenant;
    }
}
