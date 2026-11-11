<?php declare(strict_types=1);

namespace TextMeFlow\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Static checks on the administration module registration. The Shopware admin silently
 * drops a module whose id has no dash ("[namespace]-[name]"), which leaves an empty page,
 * and a settings tile whose route name does not exist.
 */
final class AdminModuleTest extends TestCase
{
    private const ADMIN = __DIR__ . '/../src/Resources/app/administration/src';

    public function testModuleIdIsNamespacedAndSettingsTileTargetsItsRoute(): void
    {
        $js = (string) file_get_contents(self::ADMIN . '/module/textmeflow/index.js');

        self::assertSame(1, preg_match("/Shopware\\.Module\\.register\\('([^']+)'/", $js, $m));
        $moduleId = $m[1];
        self::assertGreaterThanOrEqual(2, \count(explode('-', $moduleId)), 'module id needs a dash: ' . $moduleId);

        // Route names are "<id with dashes as dots>.<route key>".
        self::assertSame(1, preg_match("/routes:\\s*\\{\\s*(\\w+):/", $js, $r));
        $routeName = str_replace('-', '.', $moduleId) . '.' . $r[1];
        self::assertStringContainsString("to: '" . $routeName . "'", $js);

        // The connect callback sends the browser back to this path.
        self::assertSame(1, preg_match("/routePrefixPath:\\s*'([^']+)'/", $js, $p));
        $controller = (string) file_get_contents(__DIR__ . '/../src/Controller/ConnectController.php');
        self::assertStringContainsString("'/admin#/" . $p[1] . '/' . $r[1] . "'", $controller);
    }

    public function testSnippetsAreNotImportedIntoTheModule(): void
    {
        // Shopware loads ./snippet/*.json itself; importing them is rejected by the Store checks.
        $js = (string) file_get_contents(self::ADMIN . '/module/textmeflow/index.js');
        self::assertStringNotContainsString('snippets:', $js);

        foreach (['en-GB', 'de-DE', 'nl-NL', 'fr-FR'] as $locale) {
            $file = self::ADMIN . '/module/textmeflow/snippet/' . $locale . '.json';
            self::assertFileExists($file);
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('textmeflow', $data, $locale);
        }
    }
}
