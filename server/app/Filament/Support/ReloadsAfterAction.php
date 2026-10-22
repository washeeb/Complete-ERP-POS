<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

use function Livewire\store;

/**
 * On a document's view page: after an action changed the document (posted, approved, sent, cancelled…), the page is
 * opened again, so it shows the document as it is now and only the buttons that still apply (owner request 2026-10-22:
 * no more Post / Edit still active after posting). Actions that do not change it (print, export) leave the page as it
 * is; an action that already sends the person elsewhere (e.g. Order → the new purchase order) keeps its own way.
 */
trait ReloadsAfterAction
{
    public string $documentMark = '';

    public function mountReloadsAfterAction(): void
    {
        $this->documentMark = self::markOf($this->getRecord());
    }

    protected function afterActionCalled(Action $action): void
    {
        if (store($this)->has('redirect')) {
            return;
        }
        $fresh = $this->getRecord()->fresh();
        if (! $fresh) {
            $this->redirect(static::getResource()::getUrl('index'));

            return;
        }
        if (self::markOf($fresh) !== $this->documentMark) {
            $this->redirect(static::getResource()::getUrl('view', ['record' => $fresh]));
        }
    }

    private static function markOf(Model $m): string
    {
        return implode('|', [$m->getAttribute('status'), $m->getAttribute('version'), (string) $m->getAttribute('updated_at')]);
    }
}
