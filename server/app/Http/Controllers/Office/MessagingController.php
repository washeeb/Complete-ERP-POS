<?php

namespace App\Http\Controllers\Office;

use App\Domain\Messaging\Gateways;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\ProviderSetup;
use App\Domain\Messaging\Templates;
use App\Domain\Office\MessagingScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Messaging in the new back office (App\Domain\Office\MessagingScreen): provider setup and test messages, templates
 * (change, archive, restore) and the message log (Try again), through ProviderSetup, Templates and Messenger, as the
 * current screens. An empty credential keeps the saved one; saved credentials are never sent back.
 */
class MessagingController extends Controller
{
    use ActsOnAccess;

    public function setup(): JsonResponse
    {
        $this->allowed(MessagingScreen::canSetup());

        return response()->json((new MessagingScreen)->setup());
    }

    public function saveProvider(Request $request, string $channel, ProviderSetup $setup): JsonResponse
    {
        $this->allowed(MessagingScreen::canSetup());
        $d = $request->validate(['provider' => [$channel === 'sms' ? 'required' : 'nullable', Rule::in(array_keys(Gateways::SMS))], 'sender_id' => ['nullable', 'string', 'max:11'],
            'credentials' => ['array'], 'credentials.*' => ['nullable', 'string', 'max:1000'], 'active' => ['boolean']], ['provider.required' => 'Choose the SMS provider.']);

        return $this->act(function (Actor $actor) use ($setup, $channel, $d) {
            $setup->save($actor, $channel, ['provider' => $d['provider'] ?? null, 'sender_id' => $d['sender_id'] ?? null, 'credentials' => $d['credentials'] ?? [],
                'is_active' => (bool) ($d['active'] ?? false)]);

            return ['message' => ($channel === 'sms' ? 'SMS' : 'WhatsApp').' settings saved.'];
        });
    }

    /** A test SMS, Meta's "hello_world" on WhatsApp, or a test e-mail. */
    public function test(Request $request, string $channel, ProviderSetup $setup, Messenger $messenger): JsonResponse
    {
        $this->allowed(MessagingScreen::canSetup());
        $d = $request->validate(['to' => ['required', 'string', 'max:255', $channel === 'email' ? 'email' : 'regex:/^[+0-9 ()-]{7,20}$/']], ['to.*' => 'Enter a valid '.($channel === 'email' ? 'e-mail address.' : 'number.')]);

        return $this->act(function (Actor $actor) use ($setup, $messenger, $channel, $d) {
            $m = $channel === 'email'
                ? $messenger->send($actor->tenantId, 'email', $d['to'], 'This is a test e-mail from '.config('brand.name').'. E-mail works.', 'Test e-mail', 'TEST', $actor->name)
                : $setup->test($actor, $channel, $d['to']);

            return ['sent' => $m->status === 'sent', 'message' => $m->status === 'sent' ? 'Sent.' : 'Not sent: '.($m->error ?: (Message::STATUSES[$m->status] ?? $m->status))];
        });
    }

    public function templates(Request $request): JsonResponse
    {
        $this->allowed(MessagingScreen::canTemplates());

        return response()->json((new MessagingScreen)->templates($request->query()));
    }

    public function saveTemplate(Request $request, string $template, Templates $templates): JsonResponse
    {
        $this->allowed(MessagingScreen::canTemplates());
        $t = MessagingScreen::templatesQuery()->find($template) ?? abort(404);
        $d = $request->validate(['active' => ['boolean'], 'subject' => ['nullable', 'string', 'max:255'], 'body' => ['required', 'string', 'max:5000'],
            'whatsapp_template' => ['nullable', 'string', 'max:80'], 'whatsapp_language' => ['nullable', 'string', 'max:10']], ['body.required' => 'A message needs its text.']);

        return $this->act(function (Actor $actor) use ($templates, $t, $d) {
            $templates->save($actor, $t, ['is_active' => (bool) ($d['active'] ?? false), 'subject' => $d['subject'] ?? null, 'body' => $d['body'],
                'whatsapp_template' => $d['whatsapp_template'] ?? null, 'whatsapp_language' => $d['whatsapp_language'] ?? null]);

            return ['message' => 'Template saved.'];
        });
    }

    public function archiveTemplate(Request $request, string $template, string $step, ArchiveService $archive): JsonResponse
    {
        $this->allowed(MessagingScreen::canTemplates());
        $t = MessagingScreen::templatesQuery()->find($template) ?? abort(404);
        $d = $request->validate(['reason' => [$step === 'archive' ? 'required' : 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);

        return $this->act(function (Actor $actor) use ($archive, $t, $step, $d) {
            $step === 'archive' ? $archive->archive($actor, $t, $d['reason']) : $archive->restore($actor, $t);

            return ['message' => $step === 'archive' ? 'Archived.' : 'Restored.'];
        });
    }

    public function log(Request $request): JsonResponse
    {
        $this->allowed(MessagingScreen::canLog());

        return response()->json((new MessagingScreen)->log($request->query()));
    }

    /** Try a failed or not-sent message again: queued, the relay sends it within seconds (as the current screen). */
    public function retry(string $message): JsonResponse
    {
        $this->allowed(MessagingScreen::canLog());
        $m = Message::find($message) ?? abort(404);
        if (! in_array($m->status, ['failed', 'not_configured'], true)) {
            return response()->json(['error' => 'not_failed', 'detail' => 'Only a failed or not-sent message can be tried again.'], 422);
        }
        $m->forceFill(['status' => 'queued', 'attempts' => 0, 'next_attempt_at' => now(), 'error' => null])->save();

        return response()->json(['message' => 'Queued: it is sent within seconds.', 'row' => MessagingScreen::messageOf($m->fresh())]);
    }
}
