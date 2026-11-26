<?php

declare(strict_types=1);

namespace Atom\Component\Scheme;

/**
 * Generates Schema.org WebPage JSON-LD metadata safely without undefined property warnings.
 */
final readonly class SchemeGenerate
{
    /**
     * @param array<string, mixed> $option
     */
    public static function autoGenerate(array $option, object $data): string
    {
        $uri = \property_exists($data, 'uri') ? (string) ($data->uri ?? '') : '';
        $imageBase = \property_exists($data, 'image') ? (string) ($data->image ?? '') : '';
        $iconSuffix = isset($option['iconSvg']) ? (string) $option['iconSvg'] : '';
        $lang = \property_exists($data, 'lang') ? (string) ($data->lang ?? '') : '';
        $title = \property_exists($data, 'title') ? (string) ($data->title ?? '') : '';

        $scheme = SchemaWrapper::webPage()
            ->url($uri)
            ->image($imageBase . $iconSuffix)
            ->inLanguage($lang)
            ->name($title);

        return $scheme->toJsonLd();
    }
}
