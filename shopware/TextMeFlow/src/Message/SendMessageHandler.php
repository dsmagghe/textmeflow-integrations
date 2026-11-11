<?php declare(strict_types=1);

namespace TextMeFlow\Message;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use TextMeFlow\EventType;
use TextMeFlow\Exception\TextMeFlowApiException;
use TextMeFlow\Service\OrderMessageFactory;
use TextMeFlow\Service\Settings;
use TextMeFlow\Service\TextMeFlowClient;

/**
 * Runs in the background worker, so checkout and order updates never wait for the API.
 */
#[AsMessageHandler]
final class SendMessageHandler
{
    public function __construct(
        private readonly OrderMessageFactory $factory,
        private readonly TextMeFlowClient $client,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendMessage $message): void
    {
        $event = EventType::tryFrom($message->event);
        $token = $this->settings->getToken();
        if ($event === null || $token === null) {
            return;
        }

        $context = Context::createDefaultContext();
        $plan = $this->factory->create($message->orderId, $event, $context);
        if (!$plan->shouldSend()) {
            // Skipping is normal (no consent, no phone number, already sent): not an error.
            $this->logger->info('TextMeFlow: message skipped.', ['order' => $message->orderId, 'event' => $event->value, 'reason' => $plan->skipReason]);

            return;
        }

        try {
            $this->client->sendMessage($token, (string) $plan->to, (string) $plan->text);
        } catch (TextMeFlowApiException $e) {
            $this->logger->warning('TextMeFlow: message could not be sent.', ['order' => $message->orderId, 'event' => $event->value, 'error' => $e->getErrorCode()]);
            if ($e->isTransient()) {
                // Let the message queue retry later (rate limit, outage).
                throw $e;
            }
            $this->factory->markFailed($message->orderId, $event->value . ': ' . $e->getErrorCode(), $context);

            return;
        }

        $this->factory->markSent($message->orderId, $event, $context);
    }
}
