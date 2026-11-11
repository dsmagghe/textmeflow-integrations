<?php declare(strict_types=1);

namespace TextMeFlow\Service;

/**
 * Outcome of preparing a message for an order: either something to send, or the reason to skip it.
 */
final class MessagePlan
{
    private function __construct(
        public readonly ?string $to,
        public readonly ?string $text,
        public readonly ?string $skipReason,
    ) {
    }

    public static function send(string $to, string $text): self
    {
        return new self($to, $text, null);
    }

    public static function skip(string $reason): self
    {
        return new self(null, null, $reason);
    }

    public function shouldSend(): bool
    {
        return $this->to !== null && $this->text !== null;
    }
}
