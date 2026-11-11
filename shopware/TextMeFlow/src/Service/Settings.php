<?php declare(strict_types=1);

namespace TextMeFlow\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use TextMeFlow\EventType;

/**
 * Reads and writes the plugin settings (system config) and keeps the connection token encrypted.
 */
class Settings
{
    public const CONFIG_DOMAIN = 'TextMeFlow.config.';
    public const INTERNAL_DOMAIN = 'TextMeFlow.internal.';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly SecretBox $secretBox,
    ) {
    }

    public function getToken(): ?string
    {
        $stored = $this->systemConfig->get(self::INTERNAL_DOMAIN . 'token');

        return \is_string($stored) && $stored !== '' ? $this->secretBox->decrypt($stored) : null;
    }

    public function setToken(string $token): void
    {
        $this->systemConfig->set(self::INTERNAL_DOMAIN . 'token', $this->secretBox->encrypt($token));
    }

    public function clearToken(): void
    {
        $this->systemConfig->delete(self::INTERNAL_DOMAIN . 'token');
    }

    public function isConnected(): bool
    {
        return $this->getToken() !== null;
    }

    public function isEventEnabled(EventType $event, ?string $salesChannelId): bool
    {
        $value = $this->systemConfig->get(self::CONFIG_DOMAIN . $event->enableConfigKey(), $salesChannelId);

        return $value === null ? $event->enabledByDefault() : (bool) $value;
    }

    /**
     * Whether at least one message to customers is switched on (decides if the checkout shows the opt-in).
     */
    public function hasCustomerEventEnabled(?string $salesChannelId): bool
    {
        foreach (EventType::customerEvents() as $event) {
            if ($this->isEventEnabled($event, $salesChannelId)) {
                return true;
            }
        }

        return false;
    }

    /** The merchant's own text for an event, or null to use the default text. */
    public function getCustomText(EventType $event, ?string $salesChannelId): ?string
    {
        $value = $this->systemConfig->get(self::CONFIG_DOMAIN . $event->textConfigKey(), $salesChannelId);

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function getOwnerPhone(?string $salesChannelId): ?string
    {
        $value = $this->systemConfig->get(self::CONFIG_DOMAIN . 'ownerPhone', $salesChannelId);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function getDefaultCountry(?string $salesChannelId): ?string
    {
        $value = $this->systemConfig->get(self::CONFIG_DOMAIN . 'defaultCountry', $salesChannelId);

        return \is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : null;
    }

    /**
     * Remembers the random state of a running connect flow (single use, 10 minutes).
     */
    public function saveConnectFlow(string $state, string $returnUrl): void
    {
        $this->systemConfig->set(self::INTERNAL_DOMAIN . 'connectFlow', [
            'state' => $state,
            'return' => $returnUrl,
            'expires' => time() + 600,
        ]);
    }

    /**
     * Returns the running connect flow and removes it, so a state can never be used twice.
     *
     * @return array{state: string, return: string, expires: int}|null
     */
    public function consumeConnectFlow(): ?array
    {
        $flow = $this->systemConfig->get(self::INTERNAL_DOMAIN . 'connectFlow');
        $this->systemConfig->delete(self::INTERNAL_DOMAIN . 'connectFlow');

        if (!\is_array($flow) || !isset($flow['state'], $flow['return'], $flow['expires'])) {
            return null;
        }

        return [
            'state' => (string) $flow['state'],
            'return' => (string) $flow['return'],
            'expires' => (int) $flow['expires'],
        ];
    }
}
