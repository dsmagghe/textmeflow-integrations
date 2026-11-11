<?php declare(strict_types=1);

namespace TextMeFlow\Service;

use TextMeFlow\EventType;

/**
 * Builds the final message text: fills the placeholders and formats the amount.
 * Pure functions on plain values, so it is easy to unit test.
 */
final class MessageBuilder
{
    /** Placeholders a merchant can use in a message text. */
    public const PLACEHOLDERS = [
        'first_name', 'last_name', 'order_number', 'order_total', 'shop_name', 'tracking_url', 'tracking_code',
    ];

    /** Maximum length of one text message sent through the API. */
    private const MAX_LENGTH = 4000;

    /**
     * @param array<string, string|null> $values placeholder name => value
     */
    public function render(string $template, array $values): string
    {
        $lines = preg_split('/\R/', $template) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $rendered = (string) preg_replace_callback(
                '/\{([a-z_]+)\}/',
                static fn (array $m): string => (string) ($values[$m[1]] ?? ''),
                $line
            );

            // A line that only existed to show a tracking link or code is dropped when there is none.
            $usesTracking = str_contains($line, '{tracking_url}') || str_contains($line, '{tracking_code}');
            if ($usesTracking && trim($rendered) === '') {
                continue;
            }

            $out[] = rtrim($rendered);
        }

        $text = trim(implode("\n", $out));
        // Collapse the double spaces that appear when an optional value (e.g. a last name) is empty.
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);

        return mb_substr($text, 0, self::MAX_LENGTH);
    }

    /**
     * Uses the merchant's own text when set, otherwise the default in the customer's language.
     *
     * @param array<string, string|null> $values
     */
    public function build(EventType $event, string $locale, ?string $customText, array $values): string
    {
        $template = $customText !== null && trim($customText) !== ''
            ? $customText
            : DefaultTexts::get($event, $locale);

        return $this->render($template, $values);
    }

    /**
     * Formats an order total for the given language, e.g. "12,50 EUR" or "12.50 EUR".
     */
    public function formatAmount(float $amount, string $currencyIso, string $locale): string
    {
        $language = DefaultTexts::language($locale);
        $formatted = \in_array($language, ['nl', 'fr', 'de'], true)
            ? number_format($amount, 2, ',', '.')
            : number_format($amount, 2, '.', ',');

        return $formatted . ' ' . $currencyIso;
    }
}
