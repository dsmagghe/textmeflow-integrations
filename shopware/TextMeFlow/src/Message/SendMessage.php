<?php declare(strict_types=1);

namespace TextMeFlow\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Queue message: "send the message for this event of this order". Carries ids only;
 * the text is built when the message is handled.
 */
final class SendMessage implements AsyncMessageInterface
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $event,
    ) {
    }
}
