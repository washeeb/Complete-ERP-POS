<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Every text column in every list (bound in place of Filament's TextColumn in AppServiceProvider): one line per row
 * (owner request 2026-11-03). What a column shows under its value — a breakdown, who else, why — moves into the hover
 * tip with the column's own tip; text too long for a line is shortened, the whole of it on hover. View pages and prints
 * (infolists) still show everything.
 */
class CleanTextColumn extends TextColumn
{
    /** Characters before a column that used to wrap is shortened. */
    public const WRAPPED_LIMIT = 40;

    public function canWrap(): bool
    {
        return false;
    }

    public function getCharacterLimit(): ?int
    {
        return parent::getCharacterLimit() ?? (parent::canWrap() ? self::WRAPPED_LIMIT : null);
    }

    public function getCharacterLimitEnd(): ?string
    {
        return parent::getCharacterLimitEnd() ?? '...';
    }

    public function hasStateFormatting(): bool
    {
        return parent::hasStateFormatting() || parent::canWrap();
    }

    /** Copying a shortened value copies all of it. */
    public function getCopyableState(mixed $state): ?string
    {
        return parent::getCopyableState($state) ?? (is_scalar($state) || $state instanceof \Stringable ? (string) $state : null);
    }

    public function getDescriptionAbove(): string|Htmlable|null
    {
        return null;
    }

    public function getDescriptionBelow(): string|Htmlable|null
    {
        return null;
    }

    public function getTooltip(mixed $state = null): string|Htmlable|null
    {
        $own = parent::getTooltip($state);
        $limit = $this->getCharacterLimit();
        $text = is_scalar($state) || $state instanceof \Stringable ? trim(strip_tags((string) $this->formatState($state))) : '';
        // formatState has already shortened it: compare with the full value.
        $full = is_scalar($state) || $state instanceof \Stringable ? trim((string) $state) : '';
        $parts = array_filter([
            $limit && mb_strlen($full) > $limit && ! str_contains($text, $full) ? $full : null,
            self::plain(parent::getDescriptionAbove()),
            self::plain(parent::getDescriptionBelow()),
            self::plain($own),
        ], fn ($p) => filled($p));

        return $parts ? implode(' · ', array_unique($parts)) : null;
    }

    private static function plain(string|Htmlable|null $text): ?string
    {
        return $text === null ? null : trim(strip_tags($text instanceof Htmlable ? $text->toHtml() : $text));
    }
}
