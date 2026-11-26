<?php

declare(strict_types=1);

namespace Atom\Session;

/**
 * Session manager with PHP 8.6 strict-mode cookie defaults and CLI/Swoole in-memory isolation fallback.
 */
final class Session
{
    protected const string FLASH_KEY = 'flash_messages';

    private bool $flashMarked = false;

    /** @var array<string, mixed> */
    private array $memoryStore = [];

    private bool $useMemoryFallback = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config = []) {}

    /**
     * Ensures that the session storage is active (native SAPI session or isolated in-memory store for CLI/Swoole).
     */
    private function ensureSessionStarted(bool $force = false): void
    {
        if ($this->useMemoryFallback) {
            return;
        }

        if (\session_status() === \PHP_SESSION_ACTIVE && !$force) {
            return;
        }

        if (\PHP_SAPI === 'cli' || \headers_sent()) {
            $this->useMemoryFallback = true;
            if (isset($_SESSION)) {
                $this->memoryStore = $_SESSION;
            }
            return;
        }

        $cfg = isset($this->config['driver']) && \is_array($this->config['driver'])
            ? [...$this->config, ...$this->config['driver']]
            : $this->config;

        $sessionName = (string) ($cfg['sessionName'] ?? 'ATOM_SESSION');
        if ($sessionName !== '' && \session_status() !== \PHP_SESSION_ACTIVE) {
            \session_name($sessionName);
        }

        $lifetime = (int) ($cfg['lifetime'] ?? 7200);
        if (!empty($cfg['expireOnClose'])) {
            $lifetime = 0;
        }

        $options = [
            'cookie_lifetime' => $lifetime,
            'cookie_path' => (string) ($cfg['path'] ?? '/'),
            'cookie_domain' => (string) ($cfg['domain'] ?? ''),
            'cookie_secure' => (bool) ($cfg['secure'] ?? true),
            'cookie_httponly' => (bool) ($cfg['httpOnly'] ?? true),
            'cookie_samesite' => (string) ($cfg['sameSite'] ?? 'Lax'),
            'use_strict_mode' => (bool) ($cfg['useStrictMode'] ?? true),
        ];

        if (\session_status() !== \PHP_SESSION_ACTIVE) {
            \set_error_handler(static fn(): bool => true);
            try {
                $started = \session_start($options);
            } finally {
                \restore_error_handler();
            }

            if (!$started) {
                $this->useMemoryFallback = true;
            }
        }
    }

    /**
     * Regenerates the session ID to prevent session fixation.
     */
    public function newSession(): void
    {
        $this->ensureSessionStarted();
        if (!$this->useMemoryFallback && \session_status() === \PHP_SESSION_ACTIVE && !\headers_sent()) {
            \session_regenerate_id(true);
        }
    }

    /**
     * Marks existing flash messages for removal at the end of the request without using references (`&`).
     */
    private function ensureFlashMarked(): void
    {
        if ($this->flashMarked) {
            return;
        }

        $this->ensureSessionStarted();
        $flashMessages = $this->readRaw(self::FLASH_KEY);
        if (!\is_array($flashMessages)) {
            $flashMessages = [];
        }

        $updated = [];
        foreach ($flashMessages as $key => $flashMessage) {
            if (\is_array($flashMessage)) {
                $flashMessage['remove'] = true;
                $updated[$key] = $flashMessage;
            }
        }

        $this->writeRaw(self::FLASH_KEY, $updated);
        $this->flashMarked = true;
    }

    public function setFlash(string $key, mixed $message): void
    {
        $this->ensureFlashMarked();
        $flashMessages = $this->readRaw(self::FLASH_KEY);
        if (!\is_array($flashMessages)) {
            $flashMessages = [];
        }

        $flashMessages[$key] = [
            'remove' => false,
            'value' => $message,
        ];
        $this->writeRaw(self::FLASH_KEY, $flashMessages);
    }

    public function getFlash(string $key): mixed
    {
        $this->ensureSessionStarted();
        $flashMessages = $this->readRaw(self::FLASH_KEY);
        if (!\is_array($flashMessages) || !isset($flashMessages[$key]) || !\is_array($flashMessages[$key])) {
            return false;
        }

        return $flashMessages[$key]['value'] ?? false;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureSessionStarted();
        $this->writeRaw($key, $value);
    }

    public function get(string $key): mixed
    {
        $this->ensureSessionStarted();
        return $this->readRaw($key);
    }

    public function remove(string $key): void
    {
        $this->ensureSessionStarted();
        if ($this->useMemoryFallback) {
            unset($this->memoryStore[$key]);
            return;
        }

        unset($_SESSION[$key]);
    }

    public function __destruct()
    {
        $this->removeFlashMessages();
    }

    private function removeFlashMessages(): void
    {
        if (!$this->useMemoryFallback && \session_status() !== \PHP_SESSION_ACTIVE) {
            return;
        }

        $flashMessages = $this->readRaw(self::FLASH_KEY);
        if (!\is_array($flashMessages)) {
            return;
        }

        foreach ($flashMessages as $key => $flashMessage) {
            if (\is_array($flashMessage) && !empty($flashMessage['remove'])) {
                unset($flashMessages[$key]);
            }
        }

        $this->writeRaw(self::FLASH_KEY, $flashMessages);
    }

    protected function sessionDestroy(): void
    {
        if ($this->useMemoryFallback) {
            $this->memoryStore = [];
            return;
        }

        if (\session_status() === \PHP_SESSION_ACTIVE) {
            \session_unset();
            \session_destroy();
            \session_write_close();
        }
    }

    private function readRaw(string $key): mixed
    {
        if ($this->useMemoryFallback) {
            return $this->memoryStore[$key] ?? false;
        }

        return $_SESSION[$key] ?? false;
    }

    private function writeRaw(string $key, mixed $value): void
    {
        if ($this->useMemoryFallback) {
            $this->memoryStore[$key] = $value;
            return;
        }

        $_SESSION[$key] = $value;
    }
}
