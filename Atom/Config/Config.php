<?php

namespace Atom\Config;

use Atom\Config\LoadEnvironmentVariables;
use Atom\Config\EnvironmentVariables;

use Atom\Exception\IO\Generative\FileNotFoundGenerativeException;

class Config extends EnvironmentVariables
{

    /**
     * The path to the cached configuration files.
     *
     * This path is used to store cached configuration files.
     *
     * @var string
     */
    protected const PATH_CACHED_CONFIG = DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;

    /**
     * An array of configuration file names to load from.
     *
     * This array is used to determine which configuration files to load.
     * The keys of this array are the names of the configuration files to load,
     * and the values of this array are the paths to the configuration files.
     *
     * @var array<string, string>
     */
    protected static array $from = [];

    /**
     * An array of all possible config file names.
     *
     * @var array
     */
    protected static array $configFileName = [
        // The application configuration file.
        'app',
        // The database configuration file.
        'database',
        // The cache configuration file.
        'cache',
        // The session configuration file.
        'session',
        // The mail configuration file.
        'mail',
        // The authentication configuration file.
        'auth',
        // The filesystem configuration file.
        'filesystem',
        // The module configuration file.
        'modul',
        // The logger configuration file.
        'logger',
    ];

    /**
     * Constructs a new instance of the Config class.
     *
     * @param string $appPath The path to the application root directory.
     * @param bool $autoLoadConfig If true, the configuration files will be loaded automatically.
     * @param bool $autoLoadConfigFromCache If true, the configuration files will be loaded from cache if available.
     * @param string|null $environmentPath The path to the environment file. If null, the default path will be used.
     */
    public function __construct(string $appPath, bool $autoLoadConfig = true, bool $autoLoadConfigFromCache = true, ?string $environmentPath = null)
    {
        // If auto load config is enabled, load the configuration files
        if ($autoLoadConfig) {
            $this->autoLoad($appPath, $autoLoadConfigFromCache, $environmentPath);
        }
    }

    /**
     * Automatically loads the configuration files.
     *
     * @param string $appPath The path to the application root directory.
     * @param bool $autoLoadConfigFromCacheIfAvailable If true, the configuration files will be loaded from cache if available.
     * @param string|null $environmentPath The path to the environment file. If null, the default path will be used.
     * @return bool True if the configuration files were successfully loaded, false otherwise.
     * @throws \RuntimeException If the configuration files are not found or if the default choice is not found in the choices.
     */
    public static function autoLoad(string $appPath, bool $autoLoadConfigFromCacheIfAvailable = true, ?string $environmentPath = null): bool
    {
        if ($autoLoadConfigFromCacheIfAvailable && self::checkConfigCacheIsAvailable($appPath)) {
            // Load configuration files from cache if available
            foreach (self::getConfigFileNames() as $fileName) {
                $configData = require_once $appPath . static::PATH_CACHED_CONFIG . $fileName . '.php';
                if (\is_array($configData)) {
                    static::$from[$fileName] = $configData;
                    continue;
                }

                throw new FileNotFoundGenerativeException("Invalid config data in cache for file: %s", $fileName);
            }

            return true;
        }

        // Set the environment path
        $environmentPathAbsolute = parent::setEnvironmentPath($environmentPath, $appPath);

        // Check if the environment file exists
        if (!parent::checkEnvironmentFileExists($environmentPathAbsolute . '.env')) {
            throw new FileNotFoundGenerativeException("Environment file not found at path: %s", $environmentPathAbsolute . '.env');
        }

        // Load the environment file
        parent::load($environmentPathAbsolute);

        // Check if the required environment variables are set
        parent::ensureRequiredEnvironmentVariables();

        // Load the configuration files
        foreach (self::getConfigFileNames() as $fileName) {
            $configData = require_once $appPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $fileName . '.php';
            if (\is_array($configData)) {
                static::$from[$fileName] = $configData;
                continue;
            }

            throw new FileNotFoundGenerativeException("Invalid config data for file: %s", $fileName);
        }

        // TODO: dodać sprawdznie w w env istnieje zmiena która odpowiada za debug jeśli jest false można zapisać config do cache + $autoLoadConfigFromCacheIfAvailable na true
        // if (!static::$from["app"]["debug"] && $autoLoadConfigFromCacheIfAvailable) {

        // }

        return true;
    }

    /**
     * Retrieves the value of the specified config key.
     *
     * @param string $key The config key.
     * @param bool $useDefaultChoice If true, the default choice of the config key will be used.
     * @return mixed The value of the config key.
     * @throws \RuntimeException If the config key is not found or if the default choice is not found in the choices.
     */
    public static function get(string $key, bool $useDefaultChoice = true): mixed
    {
        // Retrieve the value of the specified config key
        $var = static::$from[$key] ?? null;
        // If the config key is not found, throw an exception
        if ($var === null) {
            throw new \RuntimeException("Config key '{$key}' not found or not set config data.");
        }
        
        // If the default choice should not be used, return the value of the config key
        if (!$useDefaultChoice) {
            return $var;
        }
            
        // If the config key has a default choice and choices, use the default choice
        /** @var array{default?: mixed, choices?: mixed} $var */
        if (isset($var['default']) && isset($var['choices'])) {
            // Retrieve the default choice
            $defaultChoice = $var['default'];

            // Retrieve the choices
            $choices = $var['choices'];

            if (!isset($choices[$defaultChoice])) {
                // If the default choice is not found in the choices, throw an exception
                throw new \RuntimeException("Default choice '{$defaultChoice}' not found in choices for config key '{$key}'.");
            }

            if (is_array($defaultChoice)) {
                foreach ($defaultChoice as $keys => $value) {
                    // If the default choice is found in the choices, return the value of the default choice
                    $var['driver'][$keys] = $choices[$value];
                }
            } else {
                // If the default choice is found in the choices, return the value of the default choice
                $var['driver'] = $choices[$defaultChoice];
            }
        }

        if (isset($var['default']) && is_array($var['default'])) {
            foreach ($var['default'] as $keys => $value) {
                if (isset($var[$keys][$value])) {
                    $var['driver'][$keys] = $var[$keys][$value];
                } else {
                    throw new \RuntimeException("Default choice '{$keys}' not found in configuration file for config value '{$value}' or key '{$key}'.");
                }
            }
        }

        // Return the value of the config key
        return $var;
    }

    /**
     * Checks if the config cache is available.
     *
     * @param string $appPath The path to the application.
     * @return bool True if the config cache is available, false otherwise.
     */
    private static function checkConfigCacheIsAvailable (string $appPath) : bool
    {
        // The cache file is stored in the application path under the
        // Config::PATH_CACHED_CONFIG directory.
        $cacheFile = $appPath . static::PATH_CACHED_CONFIG . 'database.php';

        // Check if the cache file exists and is readable.
        return \file_exists($cacheFile) && \is_readable($cacheFile);
    }

    /**
     * Gets an array of all possible config file names.
     *
     * @return array An array of all possible config file names.
     */
    private static function getConfigFileNames () : array
    {
        return static::$configFileName;
    }
}