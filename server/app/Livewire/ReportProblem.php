<?php

namespace App\Livewire;

use App\Domain\Launch\SupportReports;
use App\Domain\Remote\RemoteAccess;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use Filament\Notifications\Notification;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Report a problem" in the back office's top bar: a short description, plus the page and browser the person was on,
 * and a picture of the screen (pasted with Ctrl+V after Win+Shift+S, or attached). Someone who may give support access
 * can also let SyncLytics support connect until the problem is solved. Available to everyone signed in.
 */
class ReportProblem extends Component
{
    use WithFileUploads;

    public string $message = '';

    public string $page = '';

    public string $browser = '';

    /** @var ?TemporaryUploadedFile */
    public $screenshot = null;

    public bool $letSupportConnect = false;

    public function canGrant(): bool
    {
        return Erp::can('support.access.grant');
    }

    public function send(): void
    {
        $user = Erp::user();
        if (! $user) {
            return;
        }
        $this->resetErrorBag();
        $this->validate(['screenshot' => ['nullable', 'image', 'max:5120']]);
        try {
            $picture = $this->screenshot ? file_get_contents($this->screenshot->getRealPath()) : null;
            $r = app(SupportReports::class)->report(Erp::actor(), 'back_office', $this->message, mb_substr($this->page, 0, 255), ['browser' => mb_substr($this->browser, 0, 300)],
                screenshot: $picture ?: null);
            if ($this->letSupportConnect && $this->canGrant()) {
                app(RemoteAccess::class)->grant(Erp::actor(), ['level' => 'control', 'support_report_id' => $r->id, 'reason' => "Problem {$r->number}"]);
            }
        } catch (DomainException $e) {
            $this->addError('message', $e->getMessage());

            return;
        }
        $this->reset('message', 'screenshot', 'letSupportConnect');
        $this->dispatch('close-modal', id: 'report-problem');
        Notification::make()->success()->title("Thank you — problem {$r->number} recorded")
            ->body($r->sent_at ? 'Support has been told by e-mail.' : 'It is saved; support will see it on the list of problem reports.')->send();
    }

    public function render()
    {
        return view('livewire.report-problem');
    }
}
