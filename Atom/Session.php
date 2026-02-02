<?php

namespace Atom;

class Session
{
    protected const FLASH_KEY = 'flash_messages';
    private $flashMarked = false;

    public function __construct()
    {
        // Do not access $_SESSION in the constructor to satisfy static analysis.
    }

    private function ensureSessionStarted()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    private function ensureFlashMarked()
    {
        if ($this->flashMarked) {
            return;
        }

        $this->ensureSessionStarted();
        $flashMessages = $_SESSION[self::FLASH_KEY] ?? [];
        foreach ($flashMessages as $key => &$flashMessage) {
            $flashMessage['remove'] = true;
        }
        $_SESSION[self::FLASH_KEY] = $flashMessages;
        $this->flashMarked = true;
    }

    public function setFlash($key, $message)
    {
        $this->ensureFlashMarked();
        $_SESSION[self::FLASH_KEY][$key] = [
            'remove' => false,
            'value' => $message
        ];
    }

    public function getFlash($key)
    {
        $this->ensureSessionStarted();
        return $_SESSION[self::FLASH_KEY][$key]['value'] ?? false;
    }

    public function set($key, $value)
    {
        $this->ensureSessionStarted();
        $_SESSION[$key] = $value;
    }

    public function get($key)
    {
        $this->ensureSessionStarted();
        return $_SESSION[$key] ?? false;
    }

    public function remove($key)
    {
        $this->ensureSessionStarted();
        unset($_SESSION[$key]);
    }

    public function __destruct()
    {
        $this->removeFlashMessages();
    }

    private function removeFlashMessages()
    {
        $this->ensureSessionStarted();
        $flashMessages = $_SESSION[self::FLASH_KEY] ?? [];
        foreach ($flashMessages as $key => $flashMessage) {
            if (!empty($flashMessage['remove'])) {
                unset($flashMessages[$key]);
            }
        }
        $_SESSION[self::FLASH_KEY] = $flashMessages;
    }
}
