<?php

namespace App\Domain\Ai;

/**
 * Hides personal details before text goes to a cloud model and puts them back in the answer the person sees.
 * Names from tool results are swapped for placeholders like [Customer 3]; phone numbers and e-mail addresses anywhere
 * in the text are masked too. Local models see the real values (nothing leaves the server).
 */
final class Masker
{
    /** @var array<string, string> real value => placeholder */
    private array $map = [];

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(public readonly bool $active) {}

    /** Register a personal value (a name) and get what the model should see instead. */
    public function token(string $kind, ?string $value): ?string
    {
        if (! $this->active || $value === null || trim($value) === '') {
            return $value;
        }
        if (! isset($this->map[$value])) {
            $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
            $this->map[$value] = "[{$kind} {$this->counts[$kind]}]";
        }

        return $this->map[$value];
    }

    /** Free text going out: registered names, phone numbers and e-mails masked. */
    public function mask(string $text): string
    {
        if (! $this->active) {
            return $text;
        }
        uksort($this->map, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // longest first, so "Ama Mensah" goes before "Ama"
        foreach ($this->map as $value => $token) {
            $text = str_ireplace($value, $token, $text);
        }
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[e-mail]', $text);

        return preg_replace('/(?<!\d)(?:\+?233|0)[\s-]?\d{2}[\s-]?\d{3}[\s-]?\d{4}(?!\d)/', '[phone]', $text);
    }

    /** The model's answer coming back: placeholders replaced by the real names. */
    public function unmask(string $text): string
    {
        return $this->active ? strtr($text, array_flip($this->map)) : $text;
    }
}
