<?php declare(strict_types=1);

namespace TextMeFlow;

/**
 * The events a merchant can switch on or off. The value doubles as the suffix of the
 * plugin-config keys (enable<Value>, text<Value>) and of the order custom field that
 * remembers that a message was already sent.
 */
enum EventType: string
{
    case OrderPlaced = 'OrderPlaced';
    case OrderPaid = 'OrderPaid';
    case OrderInProgress = 'OrderInProgress';
    case OrderShipped = 'OrderShipped';
    case OrderCompleted = 'OrderCompleted';
    case OrderCancelled = 'OrderCancelled';
    case OwnerNewOrder = 'OwnerNewOrder';

    /** Messages to the merchant need no customer consent. */
    public function isForOwner(): bool
    {
        return $this === self::OwnerNewOrder;
    }

    /** Whether the message is on after installation, before the merchant touched any setting. */
    public function enabledByDefault(): bool
    {
        return $this === self::OrderPlaced || $this === self::OrderShipped;
    }

    public function enableConfigKey(): string
    {
        return 'enable' . $this->value;
    }

    public function textConfigKey(): string
    {
        return 'text' . $this->value;
    }

    public function sentFlag(): string
    {
        return 'tmf_sent_' . $this->value;
    }

    /**
     * @return list<self>
     */
    public static function customerEvents(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $e): bool => !$e->isForOwner()));
    }
}
