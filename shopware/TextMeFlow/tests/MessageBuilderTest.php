<?php declare(strict_types=1);

namespace TextMeFlow\Tests;

use PHPUnit\Framework\TestCase;
use TextMeFlow\EventType;
use TextMeFlow\Service\DefaultTexts;
use TextMeFlow\Service\MessageBuilder;

final class MessageBuilderTest extends TestCase
{
    private const VALUES = [
        'first_name' => 'An',
        'last_name' => 'Peeters',
        'order_number' => '10042',
        'order_total' => '12,50 EUR',
        'shop_name' => 'Bloemen Jan',
        'tracking_url' => 'https://track.example/abc',
        'tracking_code' => 'abc',
    ];

    public function testFillsPlaceholders(): void
    {
        $text = (new MessageBuilder())->render('Hi {first_name}, order {order_number} at {shop_name}', self::VALUES);

        self::assertSame('Hi An, order 10042 at Bloemen Jan', $text);
    }

    public function testUnknownPlaceholdersBecomeEmpty(): void
    {
        self::assertSame('Hi , ok', (new MessageBuilder())->render('Hi {nope}, ok', self::VALUES));
    }

    public function testCustomTextWinsOverDefault(): void
    {
        $text = (new MessageBuilder())->build(EventType::OrderPlaced, 'nl-NL', 'Custom {order_number}', self::VALUES);

        self::assertSame('Custom 10042', $text);
    }

    public function testBlankCustomTextFallsBackToDefaultInCustomerLanguage(): void
    {
        $text = (new MessageBuilder())->build(EventType::OrderPlaced, 'nl-BE', "  \n ", self::VALUES);

        self::assertStringStartsWith('Hallo An, bedankt voor uw bestelling 10042 bij Bloemen Jan', $text);
        self::assertStringContainsString('12,50 EUR', $text);
    }

    public function testUnknownLocaleFallsBackToEnglish(): void
    {
        $text = (new MessageBuilder())->build(EventType::OrderPlaced, 'ja-JP', null, self::VALUES);

        self::assertStringStartsWith('Hi An, thank you for your order', $text);
    }

    public function testTrackingLineIsDroppedWithoutTrackingLink(): void
    {
        $values = ['tracking_url' => null, 'tracking_code' => null] + self::VALUES;
        $text = (new MessageBuilder())->build(EventType::OrderShipped, 'en-GB', null, $values);

        self::assertSame("Hi An, your order 10042 is on its way.\n- Bloemen Jan", $text);
    }

    public function testTrackingLineIsKeptWithTrackingLink(): void
    {
        $text = (new MessageBuilder())->build(EventType::OrderShipped, 'de-DE', null, self::VALUES);

        self::assertSame("Hallo An, Ihre Bestellung 10042 ist unterwegs.\nhttps://track.example/abc\n- Bloemen Jan", $text);
    }

    public function testEmptyOptionalValueDoesNotLeaveDoubleSpaces(): void
    {
        $text = (new MessageBuilder())->build(EventType::OwnerNewOrder, 'en-GB', null, ['last_name' => ''] + self::VALUES);

        self::assertSame('New order 10042 at Bloemen Jan: 12,50 EUR from An .', $text);
    }

    public function testLongTextIsCut(): void
    {
        $text = (new MessageBuilder())->render(str_repeat('a', 5000), []);

        self::assertSame(4000, mb_strlen($text));
    }

    public function testFormatAmountPerLanguage(): void
    {
        $builder = new MessageBuilder();

        self::assertSame('1.234,50 EUR', $builder->formatAmount(1234.5, 'EUR', 'nl-NL'));
        self::assertSame('1.234,50 EUR', $builder->formatAmount(1234.5, 'EUR', 'de-DE'));
        self::assertSame('1,234.50 GBP', $builder->formatAmount(1234.5, 'GBP', 'en-GB'));
    }

    public function testEveryEventHasEveryLanguageWithoutUnknownPlaceholders(): void
    {
        foreach (EventType::cases() as $event) {
            foreach (['en', 'nl', 'fr', 'de'] as $language) {
                $text = DefaultTexts::get($event, $language);
                preg_match_all('/\{([a-z_]+)\}/', $text, $matches);
                foreach ($matches[1] as $placeholder) {
                    self::assertContains($placeholder, MessageBuilder::PLACEHOLDERS, "{$event->value}/{$language}");
                }
                self::assertNotSame('', $text, 'text must not be empty');
                self::assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', $text, 'no emoji in default texts');
            }
        }
    }
}
