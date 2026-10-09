<?php

namespace App\Filament\Pages;

use App\Domain\Branding\Branding;
use App\Filament\Support\Erp;
use App\Models\BrandAsset;
use App\Models\Tenant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Livewire\WithFileUploads;
use UnitEnum;

/**
 * The company's brand: logo (light and dark backgrounds), square icon and colour, shown on the back office, tills,
 * receipts, invoices, PDFs and the online shop. Previews show the result before anyone else sees it.
 */
class BrandingPage extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Company';

    protected static ?string $navigationLabel = 'Branding';

    protected static ?string $title = 'Your brand';

    protected static ?string $slug = 'branding';

    protected string $view = 'filament.pages.branding';

    /** @var array<string, mixed> kind => uploaded file */
    public array $files = [];

    public string $color = '';

    public bool $receiptLogo = true;

    public static function canAccess(): bool
    {
        return Erp::can('setup.company.manage');
    }

    public function mount(): void
    {
        $t = Tenant::find(Erp::user()->tenant_id);
        $this->color = (string) ($t->brand_color ?? '');
        $this->receiptLogo = (bool) $t->brand_receipt_logo;
    }

    public function brand(): array
    {
        return Branding::forTenant(Tenant::find(Erp::user()->tenant_id));
    }

    /** @return array<string, ?BrandAsset> */
    public function assets(): array
    {
        $have = BrandAsset::where('bytes', '>', 0)->get(['id', 'kind', 'mime', 'width', 'height', 'bytes', 'sha256', 'updated_at'])->keyBy('kind');

        return array_map(fn ($k) => $have[$k] ?? null, array_combine(array_keys(BrandAsset::KINDS), array_keys(BrandAsset::KINDS)));
    }

    public function suggestion(): ?string
    {
        return Branding::suggestColor(Erp::user()->tenant_id);
    }

    /** Readability of white text on the chosen colour (buttons, the till's header). */
    public function contrastNote(): ?string
    {
        $c = $this->color ?: (string) config('brand.color');
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $c)) {
            return null;
        }
        $onWhite = Branding::contrast($c, '#FFFFFF');

        return $onWhite < 3 ? 'This colour is light: text and lines in it are hard to read on white. A darker shade works better for buttons.' : null;
    }

    public function updatedFiles($value, string $kind): void
    {
        $file = $this->files[$kind] ?? null;
        if (! $file) {
            return;
        }
        try {
            $this->validate(["files.{$kind}" => ['file', 'max:2048']], ["files.{$kind}.max" => 'The image is larger than 2 MB.']);
            Erp::run(fn ($actor) => app(Branding::class)->upload($actor, $kind, $file->getRealPath()), BrandAsset::KINDS[$kind].' saved');
            if ($kind === 'logo' && $this->color === '' && ($s = $this->suggestion())) {
                $this->color = $s; // offered, saved only with "Save colour"
                Notification::make()->info()->title("Suggested colour {$s}, taken from your logo")->body('Press “Save colour and receipts” to use it.')->send();
            }
        } catch (Halt) {
            // shown by Erp::run
        } finally {
            unset($this->files[$kind]);
        }
    }

    public function remove(string $kind): void
    {
        try {
            Erp::run(fn ($actor) => app(Branding::class)->remove($actor, $kind) ?? true, 'Removed');
        } catch (Halt) {
        }
    }

    public function useSuggestion(): void
    {
        $this->color = (string) $this->suggestion();
    }

    public function saveSettings(): void
    {
        try {
            Erp::run(fn ($actor) => app(Branding::class)->saveSettings($actor, $this->color ?: null, $this->receiptLogo), 'Saved — refresh the page to see the new colour everywhere');
        } catch (Halt) {
        }
    }
}
