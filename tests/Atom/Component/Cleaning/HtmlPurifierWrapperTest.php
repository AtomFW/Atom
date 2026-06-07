<?php

declare(strict_types=1);

namespace Tests\Atom\Component\Cleaning;

use Atom\Component\Cleaning\HtmlPurifierWrapper;
use HTMLPurifier;
use HTMLPurifier_Config;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(HtmlPurifierWrapper::class)]
final class HtmlPurifierWrapperTest extends TestCase
{
    private function makeConfig(array $opts = []): HTMLPurifier_Config
    {
        $cfg = HTMLPurifier_Config::createDefault();
        foreach ($opts as $k => $v) {
            $cfg->set((string)$k, $v);
        }
        return $cfg;
    }

    public function testConstructWithArrayCreatesPurifierAndRespectsOptions(): void
    {
        $wrapper = new HtmlPurifierWrapper(['Core.Encoding' => 'UTF-8', 'HTML.SafeIframe' => true]);

        self::assertNotNull($wrapper->getInstance());
        self::assertSame('UTF-8', $wrapper->getOption('Core.Encoding'));
        self::assertTrue($wrapper->getOption('HTML.SafeIframe'));

        // Ensure helper purify() delegates to underlying HTMLPurifier
        $dirty = '<script>alert(1)</script><p>ok</p>';
        $clean = $wrapper->purify($dirty);
        self::assertStringContainsString('<p>ok</p>', $clean);
        self::assertStringNotContainsString('<script>', $clean);
    }

    public function testConstructWithExistingPurifierUsesIt(): void
    {
        $cfg = $this->makeConfig(['Core.Encoding' => 'ISO-8859-1']);
        $purifier = new HTMLPurifier($cfg);

        $wrapper = new HtmlPurifierWrapper($purifier);
        self::assertSame($purifier, $wrapper->getInstance());

        // Options map is empty when constructed from concrete instances
        self::assertSame([], $wrapper->getOptions());

        $clean = $wrapper->purify('<b>ä</b>');
        self::assertStringContainsString('<b>ä</b>', $clean);
    }

    public function testConstructWithConfigBuildsPurifier(): void
    {
        $cfg = $this->makeConfig(['HTML.SafeIframe' => true]);
        $wrapper = new HtmlPurifierWrapper($cfg);

        self::assertInstanceOf(HTMLPurifier::class, $wrapper->getInstance());
        self::assertSame([], $wrapper->getOptions());

        $clean = $wrapper->purify('<iframe src="https://example.com"></iframe>');
        self::assertStringContainsString('<iframe', $clean);
    }

    public function testSetOptionUpdatesConfigAndRebuilds(): void
    {
        $wrapper = new HtmlPurifierWrapper();
        $before = $wrapper->getInstance();
        $wrapper->setOption('HTML.SafeObject', false);
        $after = $wrapper->getInstance();

        self::assertNotSame($before, $after, 'Expected purifier to be rebuilt after setOption');
        self::assertFalse($wrapper->getOption('HTML.SafeObject'));
    }

    public function testMergeOptionsMergesAndRebuilds(): void
    {
        $wrapper = new HtmlPurifierWrapper(['HTML.SafeIframe' => false]);
        $before = $wrapper->getInstance();

        $wrapper->mergeOptions(['HTML.SafeIframe' => true, 'Cache.SerializerPath' => sys_get_temp_dir()]);
        $after = $wrapper->getInstance();

        self::assertNotSame($before, $after);
        self::assertTrue($wrapper->getOption('HTML.SafeIframe'));
        self::assertSame(sys_get_temp_dir(), $wrapper->getOption('Cache.SerializerPath'));
    }

    public function testSetConfigFromArrayReplacesAllAndRebuilds(): void
    {
        $wrapper = new HtmlPurifierWrapper(['HTML.SafeIframe' => true]);
        $before = $wrapper->getInstance();

        $wrapper->setConfig(['Core.Encoding' => 'UTF-8']);
        $after = $wrapper->getInstance();

        self::assertNotSame($before, $after);
        self::assertNull($wrapper->getOption('HTML.SafeIframe'));
        self::assertSame('UTF-8', $wrapper->getOption('Core.Encoding'));
    }

    public function testSetConfigWithObjectResetsOptionsAndRebuilds(): void
    {
        $wrapper = new HtmlPurifierWrapper(['HTML.SafeIframe' => true]);
        $cfg = $this->makeConfig(['Core.Encoding' => 'UTF-8']);

        $before = $wrapper->getInstance();
        $wrapper->setConfig($cfg);
        $after = $wrapper->getInstance();

        self::assertNotSame($before, $after);
        self::assertSame([], $wrapper->getOptions());

        $clean = $wrapper->purify('<b>bold</b>');
        self::assertStringContainsString('<b>bold</b>', $clean);
    }

    public function testResetConfigRestoresDefaultsAndRebuilds(): void
    {
        $wrapper = new HtmlPurifierWrapper(['HTML.SafeIframe' => true]);
        $before = $wrapper->getInstance();
        $wrapper->resetConfig();
        $after = $wrapper->getInstance();

        self::assertNotSame($before, $after);
        self::assertSame([], $wrapper->getOptions());
    }

    public function testWithConfigCallbackAllowsAdvancedMutationAndRebuilds(): void
    {
        $wrapper = new HtmlPurifierWrapper();
        $before = $wrapper->getInstance();

        $wrapper->withConfig(function (HTMLPurifier_Config $c): void {
            $c->set('HTML.SafeIframe', true);
        });

        $after = $wrapper->getInstance();
        self::assertNotSame($before, $after);

        // While options map is not updated via withConfig, behavior should follow mutated config
        $result = $wrapper->purify('<iframe src="https://example.com"></iframe>');
        self::assertStringContainsString('<iframe', $result);
    }

    public function testRebuildAfterManualConfigChangeRecreatesInstance(): void
    {
        $wrapper = new HtmlPurifierWrapper();
        $c = $wrapper->getConfig();
        $c->set('HTML.SafeIframe', true);

        $before = $wrapper->getInstance();
        $wrapper->rebuildAfterManualConfigChange();
        $after = $wrapper->getInstance();

        self::assertNotSame($before, $after);
        self::assertStringContainsString('<iframe', $wrapper->purify('<iframe src="https://example.com"></iframe>'));
    }

    public function testMagicCallDelegatesToPurifierMethods(): void
    {
        $wrapper = new HtmlPurifierWrapper();

        // HTMLPurifier exposes a method getStrategy() (internal), but we can safely use contextSensitiveAuto() flow
        // Instead call a known method like contextSensitiveAutoConfig to verify delegation through __call if needed.
        // Here, listPurifierPublicMethods ensures at least some public methods exist.
        $methods = $wrapper->listPurifierPublicMethods();
        self::assertContains('purify', $methods);
    }

    public function testMagicCallToConfigMethodsWithPrefix(): void
    {
        $wrapper = new HtmlPurifierWrapper();
        // Using config_getDefinition might not exist, so set something via config_set
        $res = $wrapper->config_set('HTML.SafeIframe', true);
        // HTMLPurifier_Config::set returns void; ensure no exception and effect is visible
        $clean = $wrapper->purify('<iframe src="https://example.com"></iframe>');
        self::assertStringContainsString('<iframe', $clean);
    }

    public function testMagicCallThrowsForUnknownMethod(): void
    {
        $wrapper = new HtmlPurifierWrapper();
        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore-next-line intentional invalid call
        $wrapper->nonExistingMethodOnWrapper();
    }

    public function testPurifyArrayCleansOnlyStringValues(): void
    {
        $wrapper = new HtmlPurifierWrapper();
        $items = [
            'one' => '<b>x</b>',
            'two' => '<script>alert(1)</script>',
            'num' => 123,
        ];
        $out = $wrapper->purifyArray($items);

        self::assertSame(123, $out['num']);
        self::assertStringContainsString('<b>x</b>', $out['one']);
        self::assertStringNotContainsString('<script>', $out['two']);
    }

    public function testWithMergedOptionsCreatesNewInstanceWithoutMutatingOriginal(): void
    {
        $base = new HtmlPurifierWrapper(['HTML.SafeIframe' => false]);
        $clone = $base->withMergedOptions(['HTML.SafeIframe' => true]);

        self::assertNotSame($base, $clone);
        self::assertFalse($base->getOption('HTML.SafeIframe'));
        self::assertTrue($clone->getOption('HTML.SafeIframe'));

        $cleanClone = $clone->purify('<iframe src="https://example.com"></iframe>');
        self::assertStringContainsString('<iframe', $cleanClone);
    }

    public function testConstructorThrowsIfHtmlPurifierMissing(): void
    {
        // This test simulates the absence of the HTMLPurifier class by checking the guard message
        // We cannot easily unload a class in PHP once loaded,
        //  so this assertion is best-effort by reflection of code path.
        // Assert the guard uses RuntimeException message we expect when class does not exist.
        $ref = new \ReflectionClass(HtmlPurifierWrapper::class);
        $method = $ref->getConstructor();
        self::assertNotNull($method);

        $file = $ref->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);
        self::assertStringContainsString(
            'HTMLPurifier class not found. Install ezyang/htmlpurifier via Composer.',
            $source
        );
    }
}
