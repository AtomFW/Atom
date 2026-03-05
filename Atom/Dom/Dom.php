<?php

declare(strict_types=1);

namespace Atom\Dom;

use simple_html_dom; // from simplehtmldom
use simple_html_dom_node;
use RuntimeException;

// bypass autoloader
require_once __DIR__ . "/../Lib/SimpleHtmlDom/simple_html_dom.php";

/**
 * ExtendedSimpleHtmlDom
 *
 * - extends simple_html_dom (Simple HTML DOM library)
 * - adds parentMatch() to search up the ancestor chain for selector/attribute/callback
 * - adds tidyCleanHtml() to repair & normalize HTML via ext-tidy if available or DOMDocument fallback
 *
 * Notes:
 * - nodeMatches() supports basic selectors:
 *      tag, .class, #id, [attr], [attr=value], and simple combinations of them (e.g. div.class#id[attr=val]).
 * - This design favors performance and predictability for typical server-side HTML processing.
 */
final class Dom extends simple_html_dom
{
    /**
     * Flexible parentMatch:
     *
     * Usage:
     *   // old usage (explicit node first)
     *   $parent = $this->parentMatch($node, '.parent');
     *
     *   // new usage (start selector, matcher) - similar to find()
     *   $parent = $this->parentMatch('#p1', '.parent'); // finds node '#p1' then searches up
     *
     *   // if second param (matcher) is omitted and first param is selector:
     *   $immediateParent = $this->parentMatch('#p1'); // returns direct parent of #p1
     *
     * @param simple_html_dom_node|string $startOrSelector Node object or a selector string (find-style).
     * @param string|callable|array|null $matcher If $startOrSelector is node: same as before.
     *      If $startOrSelector is string: this is the ancestor matcher.
     *      If null (and $startOrSelector is string) returns immediate parent of start node.
     * @param int $startIndex Used only when $startOrSelector is a selector string - which matched node index to use.
     * @param int|null $maxLevels maximum ancestor levels to scan (null = to root).
     * @return simple_html_dom_node|null
     */
    public function parentMatch(
        simple_html_dom_node|string $startOrSelector,
        string|callable|array|null $matcher = null,
        int $startIndex = 0,
        ?int $maxLevels = null
    ): ?simple_html_dom_node {
        // determine start node
        if (is_object($startOrSelector) && $startOrSelector instanceof \simple_html_dom_node) {
            $startNode = $startOrSelector;
            $ancestorMatcher = $matcher; // may be null => immediate parent
        } else {
            // string selector path
            $startSelector = (string)$startOrSelector;
            // if no matcher provided we will return immediate parent of found start node
            $ancestorMatcher = $matcher;
            // find the start node
            $found = $this->find($startSelector, $startIndex);
            if ($found === null) {
                return null;
            }
            // $found may be an array or node; ensure node
            $startNode = is_array($found) ? ($found[$startIndex] ?? $found[0] ?? null) : $found;
            if (!($startNode instanceof \simple_html_dom_node)) {
                return null;
            }
        }

        // if no matcher => return immediate parent
        if ($ancestorMatcher === null) {
            return $startNode->parent();
        }

        // now perform upward search (same logic as previously)
        $level = 0;
        $parent = $startNode->parent();
        while ($parent !== null) {
            if (is_callable($ancestorMatcher)) {
                try {
                    if ($ancestorMatcher($parent) === true) {
                        return $parent;
                    }
                } catch (\Throwable $e) {
                    // swallow and continue
                }
            } elseif (is_array($ancestorMatcher)) {
                // attribute matcher: ['data-role' => 'card'] or ['data-role' => null] for presence
                $matched = true;
                foreach ($ancestorMatcher as $k => $v) {
                    $attrVal = $this->getNodeAttributeValue($parent, (string)$k);
                    if ($v === null) {
                        if ($attrVal === null) {
                            $matched = false;
                            break;
                        }
                    } else {
                        if ($attrVal === null || (string)$attrVal !== (string)$v) {
                            $matched = false;
                            break;
                        }
                    }
                }
                if ($matched) {
                    return $parent;
                }
            } else { // string selector
                if ($this->nodeMatches($parent, (string)$ancestorMatcher)) {
                    return $parent;
                }
            }

            $level++;
            if ($maxLevels !== null && $level >= $maxLevels) {
                break;
            }
            $parent = $parent->parent();
        }

        return null;
    }

    /**
     * nodeMatches
     *
     * Basic matcher for a node against a simple CSS selector:
     * supports: tag, .class, #id, [attr], [attr=value], and combinations like div.class#id[attr=val]
     *
     * @param simple_html_dom_node $node
     * @param string $selector
     * @return bool
     */
    public function nodeMatches(simple_html_dom_node $node, string $selector): bool
    {
        $sel = trim($selector);
        if ($sel === '') {
            return false;
        }

        // quick optimization: if selector is '*' match any element node
        if ($sel === '*') {
            return true;
        }

        // parse tag (leading word), classes (.class), id (#id), attributes ([...])
        $tag = null;
        $classes = [];
        $id = null;
        $attrs = [];

        // Extract attribute selectors first: [name] or [name=value]
        $attrPattern = '/\[([^\]=]+)(?:=([^\]]+))?\]/';
        if (preg_match_all($attrPattern, $sel, $amatches, PREG_SET_ORDER)) {
            foreach ($amatches as $am) {
                $attrName = trim($am[1]);
                $attrVal = isset($am[2]) ? trim($am[2], " \t\n\r\0\x0B'\"") : null;
                $attrs[$attrName] = $attrVal;
            }
            // remove attribute parts from selector string
            $sel = preg_replace($attrPattern, '', $sel);
        }

        // Extract id (#id)
        if (preg_match('/#([A-Za-z0-9\-\_:\.]+)/', $sel, $idm)) {
            $id = $idm[1];
            $sel = str_replace('#' . $id, '', $sel);
        }

        // Extract classes (.class)
        if (preg_match_all('/\.([A-Za-z0-9\-\_]+)/', $sel, $cm)) {
            foreach ($cm[1] as $c) {
                $classes[] = $c;
            }
            // remove classes from selector
            $sel = preg_replace('/\.([A-Za-z0-9\-\_]+)/', '', $sel);
        }

        // remaining token should be tag (if present)
        $sel = trim($sel);
        if ($sel !== '') {
            $tag = $sel;
        }

        // Now check against node
        // check tag
        if ($tag !== null && strcasecmp($node->tag, $tag) !== 0) {
            return false;
        }

        // check id
        if ($id !== null) {
            $nodeId = $node->id ?? ($node->getAttribute ? $node->getAttribute('id') : ($node->id ?? null));
            if ($nodeId === null || (string)$nodeId !== (string)$id) {
                return false;
            }
        }

        // check classes - ensure node has all classes
        if (!empty($classes)) {
            $nodeClass = $node->class ?? ($node->getAttribute ? $node->getAttribute('class') : ($node->class ?? ''));
            $nodeClassList = preg_split('/\s+/', trim((string)$nodeClass)) ?: [];
            foreach ($classes as $c) {
                if (!in_array($c, $nodeClassList, true)) {
                    return false;
                }
            }
        }

        // check attributes
        if (!empty($attrs)) {
            foreach ($attrs as $aName => $aVal) {
                $attrVal = $this->getNodeAttributeValue($node, $aName);
                if ($attrVal === null) {
                    return false;
                }
                if ($aVal !== null && (string)$attrVal !== (string)$aVal) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * getNodeAttributeValue
     *
     * Retrieve attribute value from node in a resilient way (works with various simplehtmldom versions).
     */
    private function getNodeAttributeValue(simple_html_dom_node $node, string $name): ?string
    {
        // try method
        if (method_exists($node, 'getAttribute')) {
            $v = $node->getAttribute($name);
            if ($v !== null) {
                return (string)$v;
            }
        }
        // try property access
        if (isset($node->{$name})) {
            return (string)$node->{$name};
        }
        // try attributes array
        if (isset($node->attr) && is_array($node->attr) && array_key_exists($name, $node->attr)) {
            return (string)$node->attr[$name];
        }
        return null;
    }

    /**
     * tidyCleanHtml
     *
     * Repair and clean HTML using ext-tidy if available; fallback to DOMDocument normalization.
     *
     * @param string $html
     * @param array<string,mixed> $options Tidy options (if using ext-tidy)
     * @return string Cleaned HTML string
     */
    public function tidyCleanHtml(string $html, array $options = []): string
    {
        // default tidy options (conservative)
        $default = [
            'clean' => true,
            'show-body-only' => false,
            'wrap' => 0,
            'drop-proprietary-attributes' => false,
            'output-xhtml' => false,
            'indent' => true,
            'indent-spaces' => 2,
        ];
        $opts = array_merge($default, $options);

        if (function_exists('tidy_parse_string')) {
            // ext-tidy available
            $tidy = tidy_parse_string($html, $opts, 'UTF8');
            tidy_clean_repair($tidy);
            $clean = tidy_get_output($tidy);
            if ($clean !== null && $clean !== '') {
                return (string)$clean;
            }
            // fallback to original if tidy output empty
            return $html;
        }

        // Fallback: use DOMDocument to attempt to repair HTML
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // prevent DOMDocument from adding xml declaration
        $wrapped = '<!DOCTYPE html><html><body>' . $html . '</body></html>';
        // loadHTML expects valid encoding; use UTF-8
        $loaded = $doc->loadHTML(
            $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        if ($loaded === false) {
            libxml_clear_errors();
            return $html;
        }
        // Optionally remove empty text nodes and normalize
        $doc->normalizeDocument();

        // Extract body innerHTML
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $html;
        }

        $innerHtml = '';
        foreach ($body->childNodes as $child) {
            $innerHtml .= $doc->saveHTML($child);
        }

        libxml_clear_errors();
        return $innerHtml === '' ? $html : $innerHtml;
    }
}
