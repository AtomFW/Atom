<?php

declare(strict_types=1);

namespace Atom\Component\Scheme;

use Atom\Component\Scheme\SchemaWrapper;

/**
 * Generate Schema.org JSON-LD script block
 */
final class SchemeGenerate
{

    /**
     * Auto generate schema.org JSON-LD script block
     *
     * @param array $option - The options for generating schema.org
     * @param object $data - The data for generating schema.org
     * @return string - The generated schema.org JSON-LD script block
     */
    public static function autoGenerate(array $option, object $data): string
    {
        $scheme = SchemaWrapper::webPage()
        ->url($data->uri)
        ->image($data->image . $option["iconSvg"])
        ->inLanguage($data->lang)
        ->name($data->title);
        return $scheme->toJsonLd();
    }
}
