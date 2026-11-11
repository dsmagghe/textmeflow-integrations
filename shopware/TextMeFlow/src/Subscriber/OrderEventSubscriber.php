<?php declare(strict_types=1);

namespace TextMeFlow\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use TextMeFlow\EventType;
use TextMeFlow\Message\SendMessage;
use TextMeFlow\Service\Settings;

/**
 * Turns order events into queue messages. This class does almost nothing on purpose: the
 * real work (loading the order, the API call) happens in the background worker.
 */
final class OrderEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
            'state_enter.order_transaction.state.paid' => 'onPaid',
            'state_enter.order.state.in_progress' => 'onInProgress',
            'state_enter.order_delivery.state.shipped' => 'onShipped',
            'state_enter.order.state.completed' => 'onCompleted',
            'state_enter.order.state.cancelled' => 'onCancelled',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        $this->queue($order->getId(), $order->getSalesChannelId(), EventType::OrderPlaced);
        $this->queue($order->getId(), $order->getSalesChannelId(), EventType::OwnerNewOrder);
    }

    public function onPaid(OrderStateMachineStateChangeEvent $event): void
    {
        $this->forState($event, EventType::OrderPaid);
    }

    public function onInProgress(OrderStateMachineStateChangeEvent $event): void
    {
        $this->forState($event, EventType::OrderInProgress);
    }

    public function onShipped(OrderStateMachineStateChangeEvent $event): void
    {
        $this->forState($event, EventType::OrderShipped);
    }

    public function onCompleted(OrderStateMachineStateChangeEvent $event): void
    {
        $this->forState($event, EventType::OrderCompleted);
    }

    public function onCancelled(OrderStateMachineStateChangeEvent $event): void
    {
        $this->forState($event, EventType::OrderCancelled);
    }

    private function forState(OrderStateMachineStateChangeEvent $event, EventType $type): void
    {
        $order = $event->getOrder();
        $this->queue($order->getId(), $order->getSalesChannelId(), $type);
    }

    private function queue(string $orderId, string $salesChannelId, EventType $type): void
    {
        try {
            if (!$this->settings->isConnected() || !$this->settings->isEventEnabled($type, $salesChannelId)) {
                return;
            }
            $this->bus->dispatch(new SendMessage($orderId, $type->value));
        } catch (\Throwable $e) {
            // A messaging problem must never break the checkout or an order update.
            $this->logger->error('TextMeFlow: could not queue message.', ['order' => $orderId, 'event' => $type->value, 'error' => $e->getMessage()]);
        }
    }
}
