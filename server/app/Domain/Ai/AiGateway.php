<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Providers\ClaudeProvider;
use App\Domain\Ai\Providers\OllamaProvider;
use App\Domain\Ai\Providers\OpenAiCompatibleProvider;
use App\Domain\Support\Actor;
use App\Models\AiRequest;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one way to an AI model. Decides which model may be used (System → Settings → AI: off, a local Ollama model, or
 * Claude within a monthly budget; personal data kept on the company's server), and records every call — the prompt as
 * sent (masked), the answer, tokens and cost — in the AI usage log.
 *
 * AI never posts anything by itself: callers turn answers into drafts and suggestions a person confirms.
 */
final class AiGateway
{
    public const FEATURES = ['copilot' => 'Copilot', 'invoice_scan' => 'Invoice scan', 'product_text' => 'Product description'];

    public function __construct(private readonly Settings $settings) {}

    /** Tests (and demos) can put a scripted model in place of the real ones. */
    public static function fake(?AiProvider $provider): void
    {
        $provider ? app()->instance('ai.fake', $provider) : app()->forgetInstance('ai.fake');
    }

    /** Why AI is not available for this company, or null when it is (for screens that offer AI features). */
    public function unavailableReason(string $tenantId, string $purpose = 'text'): ?string
    {
        try {
            $this->provider($tenantId, $purpose);

            return null;
        } catch (AiUnavailable $e) {
            return $e->getMessage();
        }
    }

    /**
     * The model to use. $personal: the request carries customer or staff details, which stay on the company's server
     * when "Keep personal data on your server" is on. $purpose 'vision' picks the model that reads images.
     */
    public function provider(string $tenantId, string $purpose = 'text', bool $personal = false): AiProvider
    {
        if (app()->bound('ai.fake')) {
            return app('ai.fake');
        }
        $mode = (string) $this->settings->get('ai.provider', $tenantId);
        if ($mode === 'off') {
            throw new AiUnavailable('The AI assistant is off. Forecasts, alerts and insights work without it; an administrator can switch on a local model in System → Settings → AI.');
        }
        $local = fn () => new OllamaProvider((string) $this->settings->get('ai.ollama_url', $tenantId),
            (string) $this->settings->get($purpose === 'vision' ? 'ai.ollama_vision_model' : 'ai.ollama_model', $tenantId));
        if ($mode === 'ollama' || ($personal && $this->settings->get('ai.personal_data_local', $tenantId))) {
            return $local();
        }
        if ($mode === 'cloud') {
            return $this->cloud($tenantId, $purpose);
        }
        $key = (string) config('ai.anthropic_key');
        if ($key === '') {
            throw new AiUnavailable('Claude is selected but no API key is set on the server (.env: ANTHROPIC_API_KEY).');
        }
        $cap = (int) $this->settings->get('ai.monthly_cap_usd', $tenantId) * 1_000_000;
        if ($cap <= 0) {
            throw new AiUnavailable('The cloud AI budget is 0, so Claude is not used. Set a monthly budget in System → Settings → AI, or use a local model.');
        }
        if ($this->spentThisMonth($tenantId) >= $cap) {
            throw new AiUnavailable('This month’s cloud AI budget is used up. It starts again on the 1st; built-in insights still work.');
        }

        return new ClaudeProvider($key, (string) $this->settings->get('ai.claude_model', $tenantId));
    }

    /** "Another cloud model": an OpenAI-compatible service with the key from .env (AI_CLOUD_API_KEY). */
    private function cloud(string $tenantId, string $purpose): AiProvider
    {
        $service = (string) $this->settings->get('ai.cloud_service', $tenantId);
        $preset = config("ai.cloud_services.{$service}", []);
        $url = $service === 'custom' ? trim((string) $this->settings->get('ai.cloud_url', $tenantId)) : ($preset['url'] ?? '');
        if ($url === '') {
            throw new AiUnavailable('Another cloud model is selected but its service address is empty (System → Settings → AI).');
        }
        $key = (string) config('ai.cloud_key');
        if ($key === '') {
            throw new AiUnavailable('Another cloud model is selected but no API key is set on the server (.env: AI_CLOUD_API_KEY).');
        }
        $model = trim((string) $this->settings->get($purpose === 'vision' ? 'ai.cloud_vision_model' : 'ai.cloud_model', $tenantId))
            ?: (string) ($preset[$purpose === 'vision' ? 'vision' : 'model'] ?? '');
        if ($model === '') {
            throw new AiUnavailable('Name the model to use for this service (System → Settings → AI).');
        }

        return new OpenAiCompatibleProvider($service, $url, $key, $model);
    }

    /** Millionths of a US dollar spent on cloud AI this calendar month. */
    public function spentThisMonth(string $tenantId): int
    {
        return (int) DB::table('ai_requests')->where('tenant_id', $tenantId)->where('created_at', '>=', now()->startOfMonth())->sum('cost_micro_usd');
    }

    /** Call the model and log it (also when it fails). */
    public function chat(Actor $actor, string $feature, AiProvider $provider, string $system, array $messages, array $tools = [], array $images = [], bool $json = false): AiReply
    {
        $started = microtime(true);
        $log = fn (string $status, ?AiReply $reply, ?string $error) => AiRequest::create(['tenant_id' => $actor->tenantId, 'user_id' => $actor->user?->id,
            'user_name' => $actor->name, 'feature' => $feature, 'provider' => $provider->name(), 'model' => $provider->model(),
            'prompt' => Str::limit(json_encode(array_map(fn ($m) => array_intersect_key($m, ['role' => 1, 'content' => 1, 'tool_calls' => 1]), $messages),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).($images ? ' [+'.count($images).' file(s)]' : ''), 20000),
            'response' => $reply ? Str::limit($reply->text, 20000) : null, 'tools' => $reply ? array_map(fn ($c) => ['name' => $c['name'], 'arguments' => $c['arguments']], $reply->toolCalls) : [],
            'tokens_in' => $reply?->tokensIn ?? 0, 'tokens_out' => $reply?->tokensOut ?? 0,
            'cost_micro_usd' => $reply ? $provider->costMicroUsd($reply->tokensIn, $reply->tokensOut) : 0, 'status' => $status, 'error' => $error ? Str::limit($error, 250) : null,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'created_at' => now()]);
        try {
            $reply = $provider->chat($system, $messages, $tools, $images, $json);
        } catch (AiUnavailable $e) {
            $log('error', null, $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            $log('error', null, $e->getMessage());

            throw new AiUnavailable('The AI model could not answer just now. Try again in a moment.');
        }
        $log('ok', $reply, null);

        return $reply;
    }
}
