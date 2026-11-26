<?php

declare(strict_types=1);

namespace Tests\Atom\Account;

use PHPUnit\Framework\TestCase;
use Atom\Account\Account;
use Atom\UserModel;
use Atom\Atom;
use Atom\Session\Session;

final class TestUser extends UserModel
{
    public int $id = 0;
    public static function tableName(): string { return 'users'; }
    public static function attributesTypes(): array { return []; }
    public function getDisplayName(): string { return 'Test User'; }
}

/**
 * Test suite for the Account component.
 */
class AccountTest extends TestCase
{
    private $account;
    private $mockUser;
    private $mockApp;
    private $mockSession;
    private ?Atom $originalApp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = new Account();
        
        // Create a mock for the user
        $this->mockUser = new TestUser();
        
        // Create a mock application
        $this->mockApp = $this->createMock(Atom::class);
        $this->mockSession = new Session([
            'sessionName' => 'ATOM_TEST',
            'lifetime' => 3600,
            'expireOnClose' => false,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httpOnly' => true,
            'sameSite' => 'Lax',
        ]);
        
        // Override static properties in Atom class
        if (isset(Atom::$app)) {
            $this->originalApp = Atom::$app;
        }
        Atom::$app = $this->mockApp;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Restore original values
        if ($this->originalApp instanceof Atom) {
            Atom::$app = $this->originalApp;
        }
    }

    /**
     * Test is guest returns true when no user.
     */
    public function testIsGuestReturnsTrueWhenNoUser() 
    {
        // Setting the user to not exist
        $this->mockApp->user = null;
        
        $result = Account::isGuest();
        
        $this->assertTrue($result);
    }

    /**
     * Test is guest returns false when user exists.
     */
    public function testIsGuestReturnsFalseWhenUserExists() 
    {
        // Setting that the user exists
        $this->mockApp->user = $this->mockUser;
        
        $result = Account::isGuest();
        
        $this->assertFalse($result);
    }

    /**
     * Test login sets user and session.
     */
    public function testLoginSetsUserAndSession() 
    {
        // Data preparation
        $userId = 123;

        $this->mockUser->id = $userId;
            
        $this->mockApp->session = $this->mockSession;
        
        // Execution of the method
        $result = $this->account->login($this->mockUser);
        
        // Result verification
        $this->assertTrue($result);
    }

    /**
     * Test logout removes user and session.
     */
    public function testLogoutRemovesUserAndSession() 
    {
        // User setting
        $this->mockApp->user = $this->mockUser;
        
        // Session setting
            
        $this->mockApp->session = $this->mockSession;
        
        // Executing the logout method
        $this->account->logout();
        
        // Verifying that the user has been deleted
        $this->assertNull($this->mockApp->user);
    }

    /**
     * Test magic get returns is guest value.
     */
    public function testMagicGetReturnsIsGuestValue() 
    {
        // Test for isGuest
        $this->mockApp->user = null;
        
        $result = $this->account->__get('isGuest');
        $this->assertTrue($result);
    }

    /**
     * Test magic get returns null for unknown property.
     */
    public function testMagicGetReturnsNullForUnknownProperty() 
    {
        // Test for an unknown property
        $result = $this->account->__get('unknown_property');
        $this->assertNull($result);
    }

    /**
     * Test magic get calls logout when called.
     */
    public function testMagicGetCallsLogoutWhenCalled() 
    {
        // Setting a mock for the session
            
        $this->mockApp->session = $this->mockSession;
        
        // Before calling logout we set the user
        $this->mockApp->user = $this->mockUser;
        
        // Performing the magical method
        $result = $this->account->__get('logout');
        
        // Checking that the user has been removed from the session
        $this->assertNull($this->mockApp->user);
    }

    /**
     * Test login sets user and session correctly.
     */
    public function testLoginSetsUserAndSessionCorrectly() 
    {
        // Data preparation
        $userId = 456;

        $this->mockUser->id = $userId;
        
        // Expectations
            
        $this->mockApp->session = $this->mockSession;
        
        // Execution of the login method
        $result = $this->account->login($this->mockUser);
        
        // Verification
        $this->assertTrue($result);
        $this->assertEquals($this->mockUser, Atom::$app->user);
    }

    /**
     * Test logout with no session.
     */
    public function testLogoutWithNoSession() 
    {
        // User setting
        $this->mockApp->user = $this->mockUser;
        
        // Executing the logout method with a real Session instance
        $this->mockApp->session = $this->mockSession;
        $this->account->logout();
        
        // Checking that the user has been removed from the session
        $this->assertNull($this->mockApp->user);
    }

    /**
     * Test is guest with different user states.
     */
    public function testIsGuestWithDifferentUserStates() 
    {
        // Guest test (no user)
        $this->mockApp->user = null;
        $this->assertTrue(Account::isGuest());
        
        // Test for logged in user
        $this->mockApp->user = $this->mockUser;
        $this->assertFalse(Account::isGuest());
    }

    /**
     * Test login with different user properties.
     */
    public function testLoginWithDifferentUserProperties() 
    {
        // Test with a different primary key
        $this->mockUser->id = 789;
            
        $this->mockApp->session = $this->mockSession;
        
        // Execution of the login method
        $result = $this->account->login($this->mockUser);
        
        $this->assertTrue($result);
    }
}
