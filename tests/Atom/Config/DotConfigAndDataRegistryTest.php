<?php

declare(strict_types=1);

namespace Tests\Atom\Config;

use Atom\Atom;
use Atom\Config\Config;
use Atom\Config\DotConfig;
use Atom\Support\DataRegistry;
use Atom\Support\TemplateInterpolator;
use Atom\View;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class DotConfigAndDataRegistryTest extends TestCase
{
    private string $tempDir;
    private ?Atom $previousApp = null;

    protected function setUp(): void
    {
        $this->tempDir = \dirname(__DIR__, 3) . '/runtime/temp/dot_cfg_test_' . \uniqid();
        \mkdir($this->tempDir . '/resources/Views/Layouts', 0777, true);

        if (isset(Atom::$app)) {
            $this->previousApp = Atom::$app;
        }

        $ref = new ReflectionClass(Config::class);
        $fromProp = $ref->getProperty('from');
        $fromProp->setValue(null, [
            'app' => [
                'name' => 'AtomScaleApp',
                'connectionInformation' => [
                    'on' => true,
                    'extension' => true,
                ],
                'templateData' => [
                    'on' => true,
                    'escapeHtml' => true,
                ],
            ],
            'database' => [
                'default' => 'mysql',
                'choices' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'host' => '10.10.0.5',
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $ref = new ReflectionClass(Config::class);
        $fromProp = $ref->getProperty('from');
        $fromProp->setValue(null, []);

        if ($this->previousApp instanceof Atom) {
            Atom::$app = $this->previousApp;
        }

        if (\is_dir($this->tempDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $p = $file->getPathname();
                $file->isDir() ? \rmdir($p) : \unlink($p);
            }
            \rmdir($this->tempDir);
        }
    }

    public function testDotConfigResolvesAtomCoreAppShorthandAndNestedFiles(): void
    {
        self::assertSame('2.1.0', config('atom.core.version'));
        self::assertSame('8.6.0', atom_config('atom.core.php'));
        self::assertSame('Timonix', Config::dot('atom.core.author'));
        self::assertTrue(config('connectionInformation.extension'));
        self::assertTrue(config('app.connectionInformation.on'));
        self::assertSame('10.10.0.5', config('database.choices.mysql.host'));
        self::assertSame('10.10.0.5', config('database.driver.host'));
        self::assertSame('fallback_val', config('nonexistent.deep.key', 'fallback_val'));
        self::assertTrue(Config::hasDot('connectionInformation.extension'));
        self::assertFalse(Config::hasDot('connectionInformation.missingKey'));
    }

    public function testDataRegistryDeclaresUnicodeDotKeysAndExposesDirectNestedArray(): void
    {
        $registry = new DataRegistry();
        $registry->set('jakaśAplikacjia.core.version', '4.2.0');
        $registry->set('jakaśAplikacjia.core.features', ['cluster', 'oauth', 'mcp']);
        $registry['jakaśAplikacjia.author.name'] = 'DevTeam';

        self::assertTrue($registry->has('jakaśAplikacjia.core.version'));
        self::assertSame('4.2.0', $registry->get('jakaśAplikacjia.core.version'));
        self::assertSame('4.2.0', $registry['jakaśAplikacjia.core.version']);

        // Direct native PHP array access (`$arr['jakaśAplikacjia']['core']['version']`)
        $rawArray = $registry->toArray();
        self::assertSame('4.2.0', $rawArray['jakaśAplikacjia']['core']['version']);
        self::assertSame(['cluster', 'oauth', 'mcp'], $rawArray['jakaśAplikacjia']['core']['features']);
        self::assertSame('DevTeam', $registry['jakaśAplikacjia']['author']['name']);

        // Global helper `atom_data()`
        atom_data('sklepOnline.koszyk.waluta', 'PLN', $registry);
        self::assertSame('PLN', atom_data('sklepOnline.koszyk.waluta', registry: $registry));
        self::assertSame('PLN', $registry->toArray()['sklepOnline']['koszyk']['waluta']);

        $registry->remove('jakaśAplikacjia.author.name');
        self::assertFalse($registry->has('jakaśAplikacjia.author.name'));
        self::assertTrue($registry->has('jakaśAplikacjia.core.version'));
    }

    public function testViewAutomaticallyReplacesDotKeysInHtmlWithXssProtection(): void
    {
        /** @var Atom $app */
        $app = (new ReflectionClass(Atom::class))->newInstanceWithoutConstructor();
        $app->layout = 'main';
        $app->controller = null;
        $app->data = new DataRegistry();
        $app->view = new View();

        Atom::$ROOT_DIR = $this->tempDir . \DIRECTORY_SEPARATOR;
        Atom::$app = $app;

        \file_put_contents(
            $this->tempDir . '/resources/Views/Layouts/main.php',
            '<!doctype html><html><head><meta name="ver" content="{{jakaśAplikacjia.core.version}}"></head><body>{{content}}<footer>Atom {{atom.core.version}}</footer></body></html>',
        );

        \file_put_contents(
            $this->tempDir . '/resources/Views/dashboard.php',
            '<h1>{{jakaśAplikacjia.ui.title}}</h1><div class="safe">{{unsafe.script}}</div><div class="raw">{!! trusted.badge !!}</div><span>{{unresolved.clientToken}}</span>',
        );

        atom_data('jakaśAplikacjia.core.version', '9.1.5');
        atom_data('jakaśAplikacjia.ui.title', 'Panel Klastra Atom');
        $app->view->assign('unsafe.script', '<script>alert(1)</script>');
        $app->view->assign('trusted.badge', '<strong>Verified</strong>');

        $html = $app->view->renderView('dashboard');

        self::assertStringContainsString('<meta name="ver" content="9.1.5">', $html);
        self::assertStringContainsString('<h1>Panel Klastra Atom</h1>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('<div class="raw"><strong>Verified</strong></div>', $html);
        self::assertStringContainsString('<footer>Atom 2.1.0</footer>', $html);
        self::assertStringContainsString('<span>{{unresolved.clientToken}}</span>', $html);

        $inline = TemplateInterpolator::interpolate('Ext={{config:connectionInformation.extension}}');
        self::assertSame('Ext=true', $inline);
    }
}
