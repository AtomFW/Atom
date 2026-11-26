<?php

declare(strict_types=1);

namespace Atom\Head\Enum;

enum ColorScheme: string
{
    case LIGHT = 'light';
    case DARK = 'dark';
    case LIGHTDARK = 'light dark';
    case DARKLIGHT = 'dark light';
    case ONLYLIGHT = 'only light';
    case ONLYDARK = 'only dark';
}
