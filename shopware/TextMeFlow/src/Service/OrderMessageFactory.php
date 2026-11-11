<?php declare(strict_types=1);

namespace TextMeFlow\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use TextMeFlow\EventType;

/**
 * Loads an order and decides what (if anything) to send for an event: checks consent,
 * finds and normalises the phone number and renders the text in the customer's language.
 */
class OrderMessageFactory
{
    public const OPT_IN_FIELD = 'tmf_whatsapp_optin';

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly Settings $settings,
        private readonly MessageBuilder $builder,
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {
    }

    public function create(string $orderId, EventType $event, Context $context): MessagePlan
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations([
            'orderCustomer',
            'billingAddress.country',
            'deliveries.shippingOrderAddress.country',
            'deliveries.shippingMethod',
            'currency',
            'language.locale',
            'salesChannel',
        ]);

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();
        if ($order === null) {
            return MessagePlan::skip('order_not_found');
        }

        $customFields = $order->getCustomFields() ?? [];
        if (!empty($customFields[$event->sentFlag()])) {
            return MessagePlan::skip('already_sent');
        }

        $salesChannelId = $order->getSalesChannelId();
        // Typed as non-null in 6.5 and nullable in 6.6; the association can be missing either way.
        /** @var LanguageEntity|null $language */
        $language = $order->getLanguage();
        $locale = $language?->getLocale()?->getCode() ?? DefaultTexts::FALLBACK_LOCALE;

        if ($event->isForOwner()) {
            $rawNumber = $this->settings->getOwnerPhone($salesChannelId);
            if ($rawNumber === null) {
                return MessagePlan::skip('no_owner_phone');
            }
            $country = $this->settings->getDefaultCountry($salesChannelId)
                ?? $order->getBillingAddress()?->getCountry()?->getIso();
            // The merchant reads the message in the language of the order's shop.
        } else {
            // GDPR: customer messages only go out with the checkout opt-in.
            if (empty($customFields[self::OPT_IN_FIELD])) {
                return MessagePlan::skip('no_consent');
            }
            [$rawNumber, $country] = $this->customerNumber($order);
            if ($rawNumber === null) {
                return MessagePlan::skip('no_phone_number');
            }
        }

        $to = $this->phoneNormalizer->normalize($rawNumber, $country);
        if ($to === null) {
            return MessagePlan::skip('invalid_phone_number');
        }

        $text = $this->builder->build(
            $event,
            $locale,
            $this->settings->getCustomText($event, $salesChannelId),
            $this->placeholderValues($order, $locale)
        );

        return $text === '' ? MessagePlan::skip('empty_text') : MessagePlan::send($to, $text);
    }

    /**
     * Delivery address first (where the parcel goes), billing address as fallback.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function customerNumber(OrderEntity $order): array
    {
        $candidates = [];
        $delivery = $order->getDeliveries()?->first();
        if ($delivery !== null) {
            $candidates[] = $delivery->getShippingOrderAddress();
        }
        $candidates[] = $order->getBillingAddress();

        foreach ($candidates as $address) {
            if ($address instanceof OrderAddressEntity && trim((string) $address->getPhoneNumber()) !== '') {
                return [$address->getPhoneNumber(), $address->getCountry()?->getIso()];
            }
        }

        return [null, null];
    }

    /**
     * @return array<string, string|null>
     */
    private function placeholderValues(OrderEntity $order, string $locale): array
    {
        $customer = $order->getOrderCustomer();
        $salesChannel = $order->getSalesChannel();
        $delivery = $order->getDeliveries()?->first();

        $trackingCode = null;
        $trackingUrl = null;
        if ($delivery !== null) {
            $codes = array_values(array_filter($delivery->getTrackingCodes(), 'is_string'));
            $trackingCode = $codes[0] ?? null;
            $template = $delivery->getShippingMethod()?->getTrackingUrl();
            if ($trackingCode !== null && \is_string($template) && str_contains($template, '%s')) {
                $trackingUrl = str_replace('%s', rawurlencode($trackingCode), $template);
            }
        }

        return [
            'first_name' => $customer?->getFirstName(),
            'last_name' => $customer?->getLastName(),
            'order_number' => $order->getOrderNumber(),
            'order_total' => $this->builder->formatAmount(
                $order->getAmountTotal(),
                $order->getCurrency()?->getIsoCode() ?? '',
                $locale
            ),
            // The shop name from the basic settings; the sales channel name is often just "Storefront".
            'shop_name' => $this->settings->getShopName($order->getSalesChannelId())
                ?? $salesChannel?->getTranslation('name') ?? $salesChannel?->getName(),
            'tracking_url' => $trackingUrl,
            'tracking_code' => $trackingCode,
        ];
    }

    /**
     * Stores a flag on the order so the same message is never sent twice (state changes can repeat).
     */
    public function markSent(string $orderId, EventType $event, Context $context): void
    {
        $this->orderRepository->update([[
            'id' => $orderId,
            'customFields' => [$event->sentFlag() => (new \DateTimeImmutable())->format(DATE_ATOM)],
        ]], $context);
    }

    /**
     * Stores the last failure on the order, so the merchant can see it next to the order.
     */
    public function markFailed(string $orderId, string $reason, Context $context): void
    {
        $this->orderRepository->update([[
            'id' => $orderId,
            'customFields' => ['tmf_last_error' => mb_substr($reason, 0, 250)],
        ]], $context);
    }
}
