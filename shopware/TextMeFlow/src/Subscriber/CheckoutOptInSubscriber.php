<?php declare(strict_types=1);

namespace TextMeFlow\Subscriber;

use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use TextMeFlow\Service\OrderMessageFactory;
use TextMeFlow\Service\Settings;

/**
 * The WhatsApp opt-in checkbox on the checkout confirm page, and storing the answer on the order.
 */
final class CheckoutOptInSubscriber implements EventSubscriberInterface
{
    public const REQUEST_FIELD = 'tmfWhatsappOptin';

    public function __construct(
        private readonly Settings $settings,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onConfirmPageLoaded',
            CartConvertedEvent::class => 'onCartConverted',
        ];
    }

    /**
     * Shows the checkbox only when the shop is connected and a customer message is switched on.
     */
    public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $show = $this->settings->isConnected() && $this->settings->hasCustomerEventEnabled($salesChannelId);

        $event->getPage()->addExtension('textmeflowOptIn', new ArrayStruct(['show' => $show]));
    }

    /**
     * Copies the checkbox answer (unchecked = absent) to the order's custom fields.
     */
    public function onCartConverted(CartConvertedEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->request->getBoolean(self::REQUEST_FIELD)) {
            return;
        }

        $converted = $event->getConvertedCart();
        $converted['customFields'] = array_merge(
            \is_array($converted['customFields'] ?? null) ? $converted['customFields'] : [],
            [OrderMessageFactory::OPT_IN_FIELD => true]
        );
        $event->setConvertedCart($converted);
    }
}
