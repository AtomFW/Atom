<?php

declare(strict_types=1);

namespace Atom\Config;

use Dotenv\Dotenv;

/**
 * Class LoadEnvironmentVariables
 * @package Atom\Config
 *
 * Loads environment variables from a .env file.
 *
 * This class uses the Dotenv library to load environment variables from a .env file.
 *
 * @author Bas van Vliet <timonix.pl>
 */
class LoadEnvironmentVariables
{
    /**
     * Checks if the environment file exists and is readable.
     *
     * @param string $path The path to the environment file.
     * @return bool True if the file exists and is readable, false otherwise.
     */
    protected static function checkEnvironmentFileExists(string $path): bool
    {
        // Check if the file exists and is a file
        // Check if the file is readable
        // Return true if both conditions are met, false otherwise
        return \file_exists($path) && \is_file($path) && \is_readable($path);
    }

    /**
     * Loads the environment variables from the specified path.
     *
     * @param string $path The path to the environment file.
     */
    protected static function load(string $path): void
    {
        // Create a new instance of Dotenv with the specified path and the '.env' extension
        $dotenv = Dotenv::createImmutable($path, '.env');

        // Load the environment variables from the file
        $dotenv->safeLoad();
    }

    /**
     * Sets the environment path by trimming the path and adding the '.env' extension if needed.
     *
     * @param string|null $path The path to be set. If empty, the default path will be used.
     * @param string $appPath The path to the application.
     * @return string The set environment path.
     */
    protected static function setEnvironmentPath(?string $path, string $appPath): string
    {

        // if path empty use default path
        if (empty($path)) {
            return realpath($appPath) . DIRECTORY_SEPARATOR;
        }
        // trim the path to remove any trailing slashes

        $path = \trim($path, DIRECTORY_SEPARATOR);
        // if the path doesn't end with '.env', add it
        if (!\str_ends_with($path, '.env')) {
            $path .= '.env';

        }

        return $path;
    }
}
