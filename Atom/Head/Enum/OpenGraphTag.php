<?php

declare(strict_types=1);

namespace Atom\Head\Enum;

enum OpenGraphTag: string
{
    // Tagi Podstawowe (Basic)
    case TITLE = 'title';
    case TYPE = 'type';
    case IMAGE = 'image';
    case URL = 'url';

    // Tagi Uzupełniające (Optional)
    case DESCRIPTION = 'description';
    case SITE_NAME = 'site_name';
    case LOCALE = 'locale';
    case DETERMINER = 'determiner';

    // Tagi Type: Basic
    case WEBSITE = 'website';
    case BOOK = 'book';
    case PROFILE = 'profile';

    // Artykuły (Article)
    case ARTICLE = 'article';
    case ARTICLE_PUBLISHED_TIME = 'article:published_time';
    case ARTICLE_MODIFIED_TIME = 'article:modified_time';
    case ARTICLE_AUTHOR = 'article:author';
    case ARTICLE_SECTION = 'article:section';
    case ARTICLE_TAG = 'article:tag';

    // Multimedia - Obrazy (Image Details)
    case IMAGE_SECURE_URL = 'image:secure_url';
    case IMAGE_TYPE = 'image:type';
    case IMAGE_WIDTH = 'image:width';
    case IMAGE_HEIGHT = 'image:height';
    case IMAGE_ALT = 'image:alt';

    // Multimedia - Video (Video Details)
    case VIDEO = 'video';
    case VIDEO_ACTOR = 'video:actor';
    case VIDEO_SECURE_URL = 'video:secure_url';
    case VIDEO_TYPE = 'video:type';
    case VIDEO_WIDTH = 'video:width';
    case VIDEO_HEIGHT = 'video:height';
    case VIDEO_DURATION = 'video:duration';

    // Multimedia - Audio (Audio Details)
    case AUDIO = 'audio';
    case AUDIO_SECURE_URL = 'audio:secure_url';
    case AUDIO_TYPE = 'audio:type';
    case AUDIO_DURATION = 'audio:duration';

    // Muzyka (Music)
    case MUSIC_DURATION = 'music:duration';
    case MUSIC_ALBUM = 'music:album';
    case MUSIC_MUSICIAN = 'music:musician';
}
