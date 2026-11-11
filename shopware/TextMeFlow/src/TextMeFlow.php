<?php declare(strict_types=1);

namespace TextMeFlow;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use TextMeFlow\Service\ConnectionService;

/**
 * TextMeFlow for Shopware 6: WhatsApp order updates through the TextMeFlow API.
 */
class TextMeFlow extends Plugin
{
    private const CUSTOM_FIELD_SET = 'tmf_whatsapp';

    public function install(InstallContext $installContext): void
    {
        /** @var EntityRepository<EntityCollection<Entity>> $repository */
        $repository = $this->container->get('custom_field_set.repository');
        $label = [
            'en-GB' => 'TextMeFlow (WhatsApp)',
            'de-DE' => 'TextMeFlow (WhatsApp)',
            'nl-NL' => 'TextMeFlow (WhatsApp)',
            'fr-FR' => 'TextMeFlow (WhatsApp)',
        ];

        // Fixed ids make this idempotent: installing twice updates instead of duplicating.
        $repository->upsert([[
            'id' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET),
            'name' => self::CUSTOM_FIELD_SET,
            'config' => ['label' => $label],
            'relations' => [['id' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET . '_order'), 'entityName' => 'order']],
            'customFields' => [[
                'id' => Uuid::fromStringToHex('tmf_whatsapp_optin'),
                'name' => 'tmf_whatsapp_optin',
                'type' => CustomFieldTypes::BOOL,
                'config' => [
                    'label' => [
                        'en-GB' => 'Customer agreed to WhatsApp updates',
                        'de-DE' => 'Kunde stimmt WhatsApp-Updates zu',
                        'nl-NL' => 'Klant gaf toestemming voor WhatsApp-updates',
                        'fr-FR' => 'Le client accepte les mises à jour WhatsApp',
                    ],
                    'type' => 'checkbox',
                    'componentName' => 'sw-field',
                    'customFieldType' => 'checkbox',
                    'customFieldPosition' => 1,
                ],
            ]],
        ]], $installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Invalidate the token at TextMeFlow while it is still in the database (best effort).
        try {
            /** @var ConnectionService $connection */
            $connection = $this->container->get(ConnectionService::class);
            $connection->disconnect();
        } catch (\Throwable) {
            // Uninstalling must not fail because the API is unreachable.
        }

        $context = $uninstallContext->getContext();
        $this->removeCustomFields($context);
        $this->removeSystemConfig($context);
    }

    private function removeCustomFields(Context $context): void
    {
        /** @var EntityRepository<EntityCollection<Entity>> $repository */
        $repository = $this->container->get('custom_field_set.repository');
        $repository->delete([['id' => Uuid::fromStringToHex(self::CUSTOM_FIELD_SET)]], $context);
    }

    /**
     * Removes every system-config row of the plugin, including the internal (hidden) ones.
     */
    private function removeSystemConfig(Context $context): void
    {
        /** @var EntityRepository<EntityCollection<Entity>> $repository */
        $repository = $this->container->get('system_config.repository');
        $criteria = (new Criteria())->addFilter(new ContainsFilter('configurationKey', 'TextMeFlow.'));
        $ids = $repository->searchIds($criteria, $context)->getIds();

        if ($ids !== []) {
            $repository->delete(array_map(static fn ($id): array => ['id' => $id], $ids), $context);
        }
    }
}
