<?php

namespace Atom\Session;

/**
 * Session class
 *
 * This class is responsible for managing the session.
 *
 * It provides methods to set the title, add CSS and JavaScript files, add meta tags,
 * add link tags, add property tags, add generator tags and set other head section
 * attributes.
 *
 * @final
 */
final class Session
{
    protected const FLASH_KEY = 'flash_messages';
    private $flashMarked = false;

    /**
     * Constructs a new Session object.
     *
     * This object is responsible for managing the session.
     *
     * It provides methods to set the title, add CSS and JavaScript files, add meta tags,
     * add link tags, add property tags, add generator tags and set other head section
     * attributes.
     *
     * @param array $config The configuration for the session.
     *     The configuration should contain the following keys:
     *     - sessionName: The name of the session.
     *     - lifetime: The lifetime of the session in seconds.
     *     - expireOnClose: Whether the session should expire when the browser is closed.
     *     - path: The path where the session cookie should be available.
     *     - domain: The domain where the session cookie should be available.
     *     - secure: Whether the session cookie should be secure.
     *     - httpOnly: Whether the session cookie should be available over HTTP.
     *     - sameSite: Whether the session cookie should be available on the same site.
     */
    public function __construct(private array $config)
    {
        // Do not access $_SESSION in the constructor to satisfy static analysis.
    }

    /**
     * Ensures that the session has been started.
     *
     * If the session has not been started yet, this method will start it.
     * If the session has already been started, this method will do nothing.
     *
     * @param bool $force Whether to force the session to be started even if it has already been started.
     *
     * @return void
     */
    private function ensureSessionStarted($force = false): void
    {
        if ((session_status() !== PHP_SESSION_ACTIVE) || $force) {
            session_name($this->config['sessionName']);

            $lifetime = $this->config['lifetime'];
            if ($this->config['expireOnClose']) {
                $lifetime = 0;
            }

            session_start([
                'cookie_lifetime' => $lifetime,
                'cookie_path' => $this->config['path'],
                'cookie_domain' => $this->config['domain'],
                'cookie_secure' => $this->config['secure'],
                'cookie_httponly' => $this->config['httpOnly'],
                'cookie_samesite' => $this->config['sameSite'],
            ]);
        }
    }

    /**
     * Generates a new session id.
     *
     * This method will generate a new session id and regenerate the session.
     * This is useful when a user logs in or out.
     *
     * @return void
     */
    public function newSession(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Marks all the flash messages for removal.
     *
     * This method will mark all the flash messages for removal. The flash messages will be removed
     * when the session is closed.
     *
     * @return void
     */
    private function ensureFlashMarked(): void
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

    /**
     * Sets a flash message in the session.
     *
     * This method will set a flash message in the session. The flash message can be retrieved with the
     * getFlash() method. The flash message will be removed when the session is closed.
     *
     * @param string $key The key of the flash message.
     * @param mixed $message The value of the flash message.
     *
     * @return void
     */
    public function setFlash($key, $message): void
    {
        $this->ensureFlashMarked();
        $_SESSION[self::FLASH_KEY][$key] = [
            'remove' => false,
            'value' => $message
        ];
    }

    /**
     * Gets a flash message from the session.
     *
     * This method will get a flash message from the session. The flash message is set with the setFlash() method.
     * The flash message will be removed when the session is closed.
     *
     * @param string $key The key of the flash message.
     *
     * @return mixed The value of the flash message or false if the flash message does not exist.
     */
    public function getFlash($key): mixed
    {
        $this->ensureSessionStarted();
        return $_SESSION[self::FLASH_KEY][$key]['value'] ?? false;
    }

    /**
     * Sets a value in the session.
     *
     * This method will set a value in the session. The value can be retrieved with the get() method.
     * The value will be removed when the session is closed.
     *
     * @param string $key The key of the value.
     * @param mixed $value The value to set.
     *
     * @return void
     */
    public function set($key, $value): void
    {
        $this->ensureSessionStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Gets a value from the session.
     *
     * This method will get a value from the session. The value is set with the set() method.
     * The value will be removed when the session is closed.
     *
     * @param string $key The key of the value.
     *
     * @return mixed The value of the key or false if the key does not exist.
     */
    public function get($key): mixed
    {
        $this->ensureSessionStarted();
        return $_SESSION[$key] ?? false;
    }

    /**
     * Removes a value from the session.
     *
     * This method will remove a value from the session. The value is set with the set() method.
     * The value will be removed when the session is closed.
     *
     * @param string $key The key of the value.
     *
     * @return void
     */
    public function remove($key): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Destructor of the Session class.
     *
     * This method is called when the object is destroyed. It will remove all flash messages from the session.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->removeFlashMessages();
    }

    /**
     * Removes all flash messages from the session.
     *
     * This method is called when the session is closed. It will remove all flash messages from the session.
     *
     * @return void
     */
    private function removeFlashMessages(): void
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

    /**
     * Destroys the session and removes all session data.
     *
     *This method is called when the object is destroyed. It will remove all session data from the session.
     *
     * @return void
     */
    protected function sessionDestroy(): void
    {
        session_unset();
        session_destroy();
        session_write_close();
    }
}
