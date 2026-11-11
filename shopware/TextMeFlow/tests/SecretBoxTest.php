<?php declare(strict_types=1);

namespace TextMeFlow\Tests;

use PHPUnit\Framework\TestCase;
use TextMeFlow\Service\SecretBox;

final class SecretBoxTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $box = new SecretBox('app-secret');
        $stored = $box->encrypt('tmfc_token');

        self::assertStringNotContainsString('tmfc_token', $stored);
        self::assertSame('tmfc_token', $box->decrypt($stored));
    }

    public function testWrongSecretOrGarbageGivesNull(): void
    {
        $stored = (new SecretBox('one'))->encrypt('tmfc_token');

        self::assertNull((new SecretBox('two'))->decrypt($stored));
        self::assertNull((new SecretBox('one'))->decrypt('not encrypted'));
        self::assertNull((new SecretBox('one'))->decrypt('enc1:%%%'));
    }
}
