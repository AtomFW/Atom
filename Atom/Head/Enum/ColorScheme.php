<?php

declare(strict_types=1);

namespace Atom\Head\Enum;

/**
 * Enum for color scheme
 *
 * @package Atom\Head\Enum
 *
 * @method string LIGHT()
 * @method string DARK()
 * @method string LIGHTDARK()
 * @method string DARKLIGHT()
 * @method string ONLYLIGHT()
 * @method string ONLYDARK()
 */
enum ColorScheme: string
{
    case LIGHT = 'light';
    case DARK = 'dark';
    case LIGHTDARK = 'light dark';
    case DARKLIGHT = 'dark light';
    case ONLYLIGHT = 'only light';
    case ONLYDARK = 'only dark';
}
