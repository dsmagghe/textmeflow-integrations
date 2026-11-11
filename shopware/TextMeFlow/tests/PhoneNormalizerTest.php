<?php declare(strict_types=1);

namespace TextMeFlow\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TextMeFlow\Service\PhoneNormalizer;

final class PhoneNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: ?string}>
     */
    public static function numbers(): iterable
    {
        yield 'belgian mobile national' => ['0470 12 34 56', 'BE', '+32470123456'];
        yield 'belgian with dashes' => ['0470-12-34-56', 'BE', '+32470123456'];
        yield 'already international' => ['+32 470 12 34 56', 'BE', '+32470123456'];
        yield 'international ignores country' => ['+31 6 12345678', 'BE', '+31612345678'];
        yield '00 prefix' => ['0032470123456', 'NL', '+32470123456'];
        yield 'trunk zero in brackets' => ['+32 (0)470 12 34 56', 'BE', '+32470123456'];
        yield 'dutch mobile' => ['06 12345678', 'NL', '+31612345678'];
        yield 'german mobile' => ['0151 23456789', 'de', '+4915123456789'];
        yield 'french mobile' => ['06 12 34 56 78', 'FR', '+33612345678'];
        yield 'dots and slashes' => ['0470/12.34.56', 'BE', '+32470123456'];
        yield 'uk' => ['07911 123456', 'GB', '+447911123456'];
        yield 'us without leading zero' => ['(415) 555-2671', 'US', '+14155552671'];
        yield 'italian keeps leading zero' => ['06 1234 5678', 'IT', '+390612345678'];
        yield 'swiss' => ['079 123 45 67', 'CH', '+41791234567'];
        yield 'surrounding whitespace' => ["  0470123456\n", 'BE', '+32470123456'];
        yield 'no country, national number' => ['0470123456', null, null];
        yield 'unknown country' => ['0470123456', 'ZZ', null];
        yield 'empty' => ['   ', 'BE', null];
        yield 'null' => [null, 'BE', null];
        yield 'letters only' => ['abc', 'BE', null];
        yield 'too short' => ['+32 12', 'BE', null];
        yield 'too long' => ['+3247012345678901234', 'BE', null];
    }

    #[DataProvider('numbers')]
    public function testNormalize(?string $raw, ?string $country, ?string $expected): void
    {
        self::assertSame($expected, (new PhoneNormalizer())->normalize($raw, $country));
    }
}
