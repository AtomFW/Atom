<?php

declare(strict_types=1);

namespace Tests\Atom\Dom;

use PHPUnit\Framework\TestCase;
use simple_html_dom;
use simple_html_dom_node;
use Atom\Dom\Dom;

// Ensure simplehtmldom classes are available for testing.
// In a real project, these would be handled by Composer's autoloader.

require_once __DIR__ . "/../../../Atom/Lib/SimpleHtmlDom/simple_html_dom.php";

class DomTest extends TestCase
{
    public function testTidyCleanHtml()
    {
        $html = '<!DOCTYPE html><html><body><p>Test</p></body></html>';
        $dom = new Dom();
        // $dom->load($html);
        $this->assertEquals($html, $dom->tidyCleanHtml(
            $html,
            ['clean' => false, 'indent-spaces' => 0, 'indent' => false, 'tidy-mark' => false, 'wrap' => 0, 'vertical-space' => false, 'break-before-br' => false]
        ));
    }
}
