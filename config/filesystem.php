<?php

declare(strict_types=1);

/**
 * File system configuration template.
 */

return [
    /**
     * The default filesystem driver.
     *
     * Available drivers:
     * local - provide some private and public addresses
     * storage - provide some addresses for storing data Atom
     * node - provide some node addresses where storage Atom is controlled
     *
     * @var string
     */
    'default' => env('FILESYSTEM_DRIVER', 'local'), // Defaults to 'local'

    /**
     * local - provide some private and public addresses
     * storage - provide some addresses for storing data Atom
     * node - provide some node addresses where storage Atom is controlled
     * 
     * available drivers: local
     * 
     * @var array
     */
    'choices' => [
        'local' => [
            // only used in domain private network (login only / use on public page alerdy has blocked) 
            'private' => [
                'driver' => 'private',
                'root' => env('FILESYSTEM_LOCAL_ROOT', 'storage/private'),
                'throw' => false,
                'report' => false,
            ],
            'public' => [
                'driver' => 'public',
                'root' => env('FILESYSTEM_PUBLIC_ROOT', 'storage/public'),
                'url' => env('FILESYSTEM_PUBLIC_URL', rtrim(env('APP_URL'), "/") . '/storage'),
                'visibility' => 'public', // private (only for app domain) | public (visible from the Internet as from anywhere)
                'throw' => false,
                'report' => false,
            ],
        ],
        'storage' => [
            'driver' => 'storage',
            'key' => env('FILESYSTEM_STORAGE_KEY'),
            'secret' => env('FILESYSTEM_STORAGE_SECRET'),
            'region' => env('FILESYSTEM_STORAGE_REGION'),
            'bucket' => env('FILESYSTEM_STORAGE_BUCKET'),
            'url' => env('FILESYSTEM_STORAGE_URL'),
        ],
        'node' => [
            'driver' => 'node',
            'key' => env('FILESYSTEM_NODE_KEY'),
            'secret' => env('FILESYSTEM_NODE_SECRET'),
            'region' => env('FILESYSTEM_NODE_REGION'),
            'bucket' => env('FILESYSTEM_NODE_BUCKET'),
            'url' => env('FILESYSTEM_NODE_URL'),
        ],
    ],

    /**
     * Enable dynamic scaling of images.
     *
     * When enabled, images will be scaled down to the requested size on the fly.
     * This can be useful for speeding up page loading, but it can also increase the load on the server.
     *
     * @var bool
     */
    'dynamicScaleImages' => env('FILESYSTEM_DYNAMIC_SCALE_IMAGES', false), // enable dynamic scaling of images

    /**
     * Configuration for the directory structure of the file system.
     *
     * @var array
     */
    'dirStructure' => [
        'isOneFolder' => env('FILESYSTEM_IS_ONE_FOLDER', false), // root folder for all files
        'yearFolder' => env('FILESYSTEM_YEAR_FOLDER', true),    
        'monthFolder' => env('FILESYSTEM_MONTH_FOLDER', true),
        'dayFolder' => env('FILESYSTEM_DAY_FOLDER', true),
        'hourFolder' => env('FILESYSTEM_HOUR_FOLDER', false), // useful for larger amounts of data
        'minuteFolder' => env('FILESYSTEM_MINUTE_FOLDER', false), // useful for larger amounts of data
    ],

    /**
     * Configuration for image miniatures.
     *
     * Image miniatures are small versions of images that are used to speed up page loading.
     * They are generated on the fly when the image is requested.
     *
     * This configuration is used to determine the folder structure for the image miniatures.
     *
     * @var array
     */
    'imageMiniatureDir' => [
        'isOneFolder' => env('FILESYSTEM_MINIATURE_IS_ONE_FOLDER', false), // root folder for all files
        'inDirStructure' => env('FILESYSTEM_MINIATURE_IN_DIR_STRUCTURE', true), // use a folder structure
        'yearFolder' => env('FILESYSTEM_MINIATURE_YEAR_FOLDER', true),    
        'monthFolder' => env('FILESYSTEM_MINIATURE_MONTH_FOLDER', true),
        'dayFolder' => env('FILESYSTEM_MINIATURE_DAY_FOLDER', true),
        'hourFolder' => env('FILESYSTEM_MINIATURE_HOUR_FOLDER', false), // useful for larger amounts of data
        'minuteFolder' => env('FILESYSTEM_MINIATURE_MINUTE_FOLDER', false), // useful for larger amounts of data
        'prefix' => env('FILESYSTEM_MINIATURE_PREFIX', 'miniature') . "_",
        'width' => env('FILESYSTEM_MINIATURE_WIDTH', 75),
        'height' => env('FILESYSTEM_MINIATURE_HEIGHT', 50),
        'quality' => env('FILESYSTEM_MINIATURE_QUALITY', 80),
        'format' => env('FILESYSTEM_MINIATURE_FORMAT', 'default'),
    ],
    
    /**
     * Force the file system to convert all uploaded files to a certain format.
     *
     * This is useful when you want to ensure that all uploaded files are in a certain format,
     * for example when you want to ensure that all images are in webp format.
     *
     * @var bool
     */
    'forceFormat' => env('FILESYSTEM_FORCE_FORMAT', true),

    /**
     * Format to use when converting files to web-friendly formats.
     * See https://developers.google.com/web/updates/2016/12/webm-in-chrome for more information.
     *
     * @var array
     */
    'webFormat' => [
        /**
         * Image format to use when converting to web-friendly format.
         * See https://developers.google.com/web/updates/2016/12/webm-in-chrome for more information.
         *
         * @var string
         */
        'image' => "webp",

        /**
         * Video format to use when converting to web-friendly format.
         * See https://developers.google.com/web/updates/2016/12/webm-in-chrome for more information.
         *
         * @var string
         */
        'video' => "webm",

        /**
         * Audio format to use when converting to web-friendly format.
         * See https://developers.google.com/web/updates/2016/12/webm-in-chrome for more information.
         *
         * @var string
         */
        'audio' => "weba",
    ],

    /**
     * Whether to automatically convert GIFs to MP4 and move them to the video folder
     * when uploading a GIF file.
     *
     * @var bool
     */
    'autoConvertGifToMove' => env('FILESYSTEM_AUTO_CONVERT_GIF_TO_MOVE', true),
];
