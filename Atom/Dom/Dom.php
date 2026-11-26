<?php

declare(strict_types=1);

namespace Atom\Dom;

use DOMDocument;
use simple_html_dom;
use simple_html_dom_node;
use Throwable;

require_once __DIR__ . '/../Lib/SimpleHtmlDom/simple_html_dom.php';

/**
 * Extended SimpleHTMLDOM parser with upward ancestor selector matching (`parentMatch`)
 * and Swoole-safe HTML repair (`tidyCleanHtml` with `try/finally` libxml state isolation).
 */
final class Dom extends simple_html_dom
{
    public function parentMatch(
        simple_html_dom_node|string $startOrSelector,
        string|callable|array|null $matcher = null,
        int $startIndex = 0,
        ?int $maxLevels = null,
    ): ?simple_html_dom_node {
        if ($startOrSelector instanceof simple_html_dom_node) {
            $startNode = $startOrSelector;
            $ancestorMatcher = $matcher;
        } else {
            $ancestorMatcher = $matcher;
            $found = $this->find($startOrSelector, $startIndex);
            if ($found === null) {
                return null;
            }
            $startNode = \is_array($found) ? ($found[$startIndex] ?? $found[0] ?? null) : $found;
            if (!$startNode instanceof simple_html_dom_node) {
                return null;
            }
        }

        if ($ancestorMatcher === null) {
            return $startNode->parent();
        }

        $level = 0;
        $parent = $startNode->parent();
        while ($parent !== null) {
            if (\is_callable($ancestorMatcher)) {
                try {
                    if ($ancestorMatcher($parent) === true) {
                        return $parent;
                    }
                } catch (Throwable) {
                    // Continue upward traversal on matcher error
                }
            } elseif (\is_array($ancestorMatcher)) {
                $matched = true;
                foreach ($ancestorMatcher as $k => $v) {
                    $attrVal = $this->getNodeAttributeValue($parent, (string) $k);
                    if ($v === null) {
                        if ($attrVal === null) {
                            $matched = false;
                            break;
                        }
                    } elseif ($attrVal === null || $attrVal !== (string) $v) {
                        $matched = false;
                        break;
                    }
                }
                if ($matched) {
                    return $parent;
                }
            } elseif ($this->nodeMatches($parent, (string) $ancestorMatcher)) {
                return $parent;
            }

            $level++;
            if ($maxLevels !== null && $level >= $maxLevels) {
                break;
            }
            $parent = $parent->parent();
        }

        return null;
    }

    public function nodeMatches(simple_html_dom_node $node, string $selector): bool
    {
        $sel = \trim($selector);
        if ($sel === '') {
            return false;
        }
        if ($sel === '*') {
            return true;
        }

        $tag = null;
        $classes = [];
        $id = null;
        $attrs = [];

        $attrPattern = '/\[([^\]=]+)(?:=([^\]]+))?\]/';
        if (\preg_match_all($attrPattern, $sel, $amatches, \PREG_SET_ORDER)) {
            foreach ($amatches as $am) {
                $attrName = \trim($am[1]);
                $attrVal = isset($am[2]) ? \trim($am[2], " \t\n\r\0\x0B'\"") : null;
                $attrs[$attrName] = $attrVal;
            }
            $sel = \preg_replace($attrPattern, '', $sel) ?? '';
        }

        if (\preg_match('/#([A-Za-z0-9\-\_:\.]+)/', $sel, $idm) === 1) {
            $id = $idm[1];
            $sel = \str_replace('#' . $id, '', $sel);
        }

        if (\preg_match_all('/\.([A-Za-z0-9\-\_]+)/', $sel, $cm)) {
            $classes = $cm[1];
            $sel = \preg_replace('/\.([A-Za-z0-9\-\_]+)/', '', $sel) ?? '';
        }

        $sel = \trim($sel);
        if ($sel !== '') {
            $tag = $sel;
        }

        if ($tag !== null && \strcasecmp($node->tag, $tag) !== 0) {
            return false;
        }

        if ($id !== null) {
            $nodeId = $this->getNodeAttributeValue($node, 'id');
            if ($nodeId === null || $nodeId !== $id) {
                return false;
            }
        }

        if ($classes !== []) {
            $nodeClass = $this->getNodeAttributeValue($node, 'class') ?? '';
            $nodeClassList = \preg_split('/\s+/', \trim($nodeClass)) ?: [];
            if (!\array_all($classes, static fn(string $c): bool => \in_array($c, $nodeClassList, true))) {
                return false;
            }
        }

        foreach ($attrs as $aName => $aVal) {
            $attrVal = $this->getNodeAttributeValue($node, $aName);
            if ($attrVal === null) {
                return false;
            }
            if ($aVal !== null && $attrVal !== $aVal) {
                return false;
            }
        }

        return true;
    }

    private function getNodeAttributeValue(simple_html_dom_node $node, string $name): ?string
    {
        $v = $node->getAttribute($name);
        if ($v !== null && $v !== false) {
            return (string) $v;
        }
        if (isset($node->{$name})) {
            return (string) $node->{$name};
        }
        if (isset($node->attr) && \is_array($node->attr) && \array_key_exists($name, $node->attr)) {
            return (string) $node->attr[$name];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function tidyCleanHtml(string $html, array $options = []): string
    {
        $opts = [
            'clean' => true,
            'show-body-only' => false,
            'wrap' => 0,
            'drop-proprietary-attributes' => false,
            'output-xhtml' => false,
            'indent' => true,
            'indent-spaces' => 2,
            ...$options,
        ];

        if (\function_exists('tidy_parse_string')) {
            $tidy = \tidy_parse_string($html, $opts, 'UTF8');
            if ($tidy !== false) {
                \tidy_clean_repair($tidy);
                $clean = \tidy_get_output($tidy);
                if ($clean !== '') {
                    return $clean;
                }
            }

            return $html;
        }

        $prevInternalErrors = \libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            $wrapped = '<!DOCTYPE html><html><body>' . $html . '</body></html>';
            $loaded = $doc->loadHTML(
                $wrapped,
                \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR | \LIBXML_NOWARNING
            );
            if ($loaded === false) {
                return $html;
            }

            $doc->normalizeDocument();
            $body = $doc->getElementsByTagName('body')->item(0);
            if ($body === null) {
                return $html;
            }

            $innerHtml = '';
            foreach ($body->childNodes as $child) {
                $innerHtml .= (string) $doc->saveHTML($child);
            }

            return $innerHtml === '' ? $html : $innerHtml;
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($prevInternalErrors);
        }
    }
}
