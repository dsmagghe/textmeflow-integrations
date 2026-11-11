<?php declare(strict_types=1);

namespace TextMeFlow\Tests;

use PHPUnit\Framework\TestCase;
use TextMeFlow\EventType;

/**
 * Shopware validates config.xml against its XSD on plugin install; an unknown element
 * (for example <subtitle> on a card) makes the install fail. The fixture is a copy of
 * src/Core/System/SystemConfig/Schema/config.xsd from shopware/core 6.6.
 */
final class ConfigXmlTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../src/Resources/config/config.xml';

    public function testConfigXmlMatchesShopwareSchema(): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(self::CONFIG));

        $previous = libxml_use_internal_errors(true);
        $valid = $dom->schemaValidate(__DIR__ . '/Fixtures/shopware-config.xsd');
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message) . ' (line ' . $e->line . ')', libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode("\n", $errors));
    }

    public function testEveryEventHasASwitchAndATextField(): void
    {
        $xml = simplexml_load_file(self::CONFIG);
        self::assertNotFalse($xml);
        $names = array_map('strval', $xml->xpath('//input-field/name') ?: []);

        foreach (EventType::cases() as $event) {
            self::assertContains($event->enableConfigKey(), $names, $event->value);
            self::assertContains($event->textConfigKey(), $names, $event->value);
        }
    }
}
