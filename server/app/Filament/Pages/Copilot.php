<?php

namespace App\Filament\Pages;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiUnavailable;
use App\Domain\Ai\Copilot as CopilotService;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** "Ask your business": questions answered from the reports the person may already see; quick questions work without AI. */
class Copilot extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'Ask your business';

    protected static ?string $title = 'Ask your business';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.copilot';

    public string $question = '';

    /** @var list<array{role: string, content: string, tables?: list<array>, notice?: ?string}> */
    public array $chat = [];

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('ai.copilot');
    }

    public function aiStatus(): ?string
    {
        return app(AiGateway::class)->unavailableReason(Erp::user()->tenant_id);
    }

    public function quickQuestions(): array
    {
        return app(CopilotService::class)->quickFor(Erp::actor());
    }

    public function ask(): void
    {
        $q = trim($this->question);
        if ($q === '') {
            return;
        }
        $history = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], array_filter($this->chat, fn ($m) => $m['content'] !== ''));
        $this->chat[] = ['role' => 'user', 'content' => $q];
        $this->question = '';
        try {
            $r = app(CopilotService::class)->ask(Erp::actor(), $q, array_values($history));
            $this->chat[] = ['role' => 'assistant', 'content' => $r['answer'], 'tables' => $r['tables'], 'notice' => $r['notice']];
        } catch (AiUnavailable $e) {
            $this->chat[] = ['role' => 'assistant', 'content' => '', 'tables' => [], 'notice' => $e->getMessage().' Try one of the quick questions — they work without AI.'];
        } catch (DomainException $e) {
            $this->chat[] = ['role' => 'assistant', 'content' => '', 'tables' => [], 'notice' => $e->getMessage()];
        }
    }

    public function quick(string $key): void
    {
        $label = CopilotService::QUICK[$key][0] ?? null;
        if (! $label) {
            return;
        }
        $this->chat[] = ['role' => 'user', 'content' => $label];
        try {
            $this->chat[] = ['role' => 'assistant', 'content' => '', 'tables' => [app(CopilotService::class)->quick(Erp::actor(), $key)], 'notice' => null];
        } catch (DomainException $e) {
            $this->chat[] = ['role' => 'assistant', 'content' => '', 'tables' => [], 'notice' => $e->getMessage()];
        }
    }

    public function clear(): void
    {
        $this->chat = [];
    }
}
