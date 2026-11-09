<?php

namespace App\Filament\Support;

use App\Support\IndustryContext;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

/**
 * Which industry a customer, supplier, category, brand, promotion, style, choice group or campaign belongs to (owner
 * 2026-11-09: every industry independent). Shown only to people who work in more than one industry, or the whole
 * company; everyone else's records go to their own industry by themselves. "Shared" is offered only where the kind of
 * record may be shared and the company allows sharing.
 */
final class IndustryField
{
    public static function make(bool $shareable = true): Select
    {
        $choices = function (?Model $record = null) use ($shareable): array {
            $c = IndustryContext::choices(Erp::user());
            if (! $shareable) {
                unset($c[IndustryContext::SHARED]);
            }
            if ($record?->exists && $record->industry_id === null && $shareable) {
                $c[IndustryContext::SHARED] = 'Shared by all industries';
            }

            return $c;
        };

        return Select::make('industry_id')->label('Industry')->options($choices)
            ->required(fn () => ! $shareable)
            ->default(fn () => IndustryContext::defaultId())
            ->formatStateUsing(fn ($state, ?Model $record) => $record?->exists ? ($state ?: ($shareable ? IndustryContext::SHARED : null)) : $state)
            ->visible(fn (?Model $record) => count(array_diff_key($choices($record), [IndustryContext::SHARED => true])) > 1
                || ($shareable && isset($choices($record)[IndustryContext::SHARED])))
            ->helperText($shareable
                ? 'Only people in this industry see it. Shared ones are seen by every industry.'
                : 'Only people in this industry see this customer.');
    }
}
