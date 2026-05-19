<?php

declare(strict_types=1);

namespace Tests\Atom\Session;

use PHPUnit\Framework\TestCase;
use Atom\Session\Session;

final class SessionTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        // Set up a mock config array for the session
        $config = [
            'sessionName' => 'test_session',
            'lifetime' => 3600,
            'expireOnClose' => false,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'Lax'
        ];

        $this->session = new Session($config);
    }

    public function testSessionStartsCorrectly(): void
    {
        $this->expectNotToPerformAssertions();
        // Just make sure no exception is thrown when starting session
        $this->session->set('test', 'value');
    }

    public function testSetAndGetSessionValue(): void
    {
        $key = 'test_key';
        $value = 'test_value';

        $this->session->set($key, $value);
        $result = $this->session->get($key);

        $this->assertEquals($value, $result);
    }

    public function testGetNonExistentSessionValue(): void
    {
        $result = $this->session->get('non_existent_key');
        $this->assertFalse($result);
    }

    public function testRemoveSessionValue(): void
    {
        $key = 'test_key';
        $value = 'test_value';

        $this->session->set($key, $value);
        $this->session->remove($key);

        $result = $this->session->get($key);
        $this->assertFalse($result);
    }

    public function testSetAndRetrieveFlashMessage(): void
    {
        $key = 'test_flash';
        $message = 'This is a flash message';

        $this->session->setFlash($key, $message);
        $result = $this->session->getFlash($key);

        $this->assertEquals($message, $result);
    }

    public function testSetAndRetrieveMultipleFlashMessages(): void
    {
        $flash1 = 'First flash message';
        $flash2 = 'Second flash message';

        $this->session->setFlash('flash1', $flash1);
        $this->session->setFlash('flash2', $flash2);

        $result1 = $this->session->getFlash('flash1');
        $result2 = $this->session->getFlash('flash2');

        $this->assertEquals($flash1, $result1);
        $this->assertEquals($flash2, $result2);
    }

    public function testSessionDestroy(): void
    {
        // This is more of an integration test since session_destroy() behavior
        // is system-level and difficult to fully test without real session handling.
        // We'll just verify that it doesn't throw any unexpected errors.
        $this->expectNotToPerformAssertions();
        // Since the destroy method is private, we can't directly test it in this case.
    }

    public function testSessionRegenerateId(): void
    {
        $this->expectNotToPerformAssertions();
        // Test if regenerate id works without throwing errors
        $this->session->newSession();
    }
}
