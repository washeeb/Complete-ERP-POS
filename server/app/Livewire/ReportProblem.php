<?php

namespace App\Livewire;

use App\Domain\Launch\SupportReports;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * "Report a problem" in the back office's top bar: a short description, plus the page and browser the person was on.
 * Available to everyone signed in — it changes no business data.
 */
class ReportProblem extends Component
{
    public string $message = '';

    public string $page = '';

    public string $browser = '';

    public function send(): void
    {
        $user = Erp::user();
        if (! $user) {
            return;
        }
        $this->resetErrorBag();
        try {
            $r = app(SupportReports::class)->report(Erp::actor(), 'back_office', $this->message, mb_substr($this->page, 0, 255), ['browser' => mb_substr($this->browser, 0, 300)]);
        } catch (DomainException $e) {
            $this->addError('message', $e->getMessage());

            return;
        }
        $this->reset('message');
        $this->dispatch('close-modal', id: 'report-problem');
        Notification::make()->success()->title("Thank you — problem {$r->number} recorded")
            ->body($r->sent_at ? 'Support has been told by e-mail.' : 'It is saved; support will see it on the list of problem reports.')->send();
    }

    public function render()
    {
        return view('livewire.report-problem');
    }
}
