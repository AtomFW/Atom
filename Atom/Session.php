<?php

declare(strict_types=1);

namespace Atom;

use Atom\Session\Session as CoreSession;

/**
 * Root-namespace facade preserving backward compatibility with `Atom\Session` while delegating to `Atom\Session\Session`.
 */
final class Session
{
    private readonly CoreSession $delegate;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->delegate = new CoreSession($config);
    }

    public function newSession(): void
    {
        $this->delegate->newSession();
    }

    public function setFlash(string $key, mixed $message): void
    {
        $this->delegate->setFlash($key, $message);
    }

    public function getFlash(string $key): mixed
    {
        return $this->delegate->getFlash($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->delegate->set($key, $value);
    }

    public function get(string $key): mixed
    {
        return $this->delegate->get($key);
    }

    public function remove(string $key): void
    {
        $this->delegate->remove($key);
    }
}
