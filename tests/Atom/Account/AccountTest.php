<?php

declare(strict_types=1);

namespace Tests\Atom\Account;

use PHPUnit\Framework\TestCase;
use Atom\Account\Account;
use Atom\UserModel;
use Atom\Atom;

class AccountTest extends TestCase
{
    private $account;
    private $mockUser;
    private $mockApp;
    private $mockSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = new Account();
        
        // Create a mock for the user
        $this->mockUser = $this->createMock(UserModel::class);
        
        // Create a mock application
        $this->mockApp = $this->createMock(\stdClass::class);
        $this->mockSession = $this->createMock(\stdClass::class);
        
        // Override static properties in Atom class
        $this->originalApp = Atom::$app ?? null;
        Atom::$app = $this->mockApp;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Restore original values
        Atom::$app = $this->originalApp;
    }

    public function testIsGuestReturnsTrueWhenNoUser() 
    {
        // Setting the user to not exist
        $this->mockApp->user = null;
        
        $result = Account::isGuest();
        
        $this->assertTrue($result);
    }

    public function testIsGuestReturnsFalseWhenUserExists() 
    {
        // Setting that the user exists
        $this->mockApp->user = $this->mockUser;
        
        $result = Account::isGuest();
        
        $this->assertFalse($result);
    }

    public function testLoginSetsUserAndSession() 
    {
        // Data preparation
        $userId = 123;
        $this->mockUser->method('primaryKey')->willReturn('id');
        $this->mockUser->id = $userId;
        
        // Expectations for the login method
        $this->mockApp->expects($this->once())
            ->method('set')
            ->with('user', $userId);
            
        $this->mockSession->expects($this->once())
            ->method('set')
            ->with('user', $userId);
            
        $this->mockApp->session = $this->mockSession;
        
        // Execution of the method
        $result = $this->account->login($this->mockUser);
        
        // Result verification
        $this->assertTrue($result);
    }

    public function testLogoutRemovesUserAndSession() 
    {
        // User setting
        $this->mockApp->user = $this->mockUser;
        
        // Session setting
        $this->mockSession->expects($this->once())
            ->method('remove')
            ->with('user');
            
        $this->mockApp->session = $this->mockSession;
        
        // Executing the logout method
        $this->account->logout();
        
        // Verifying that the user has been deleted
        $this->assertNull($this->mockApp->user);
    }

    public function testMagicGetReturnsIsGuestValue() 
    {
        // Test for isGuest
        $this->mockApp->user = null;
        
        $result = $this->account->__get('isGuest');
        $this->assertTrue($result);
    }

    public function testMagicGetReturnsNullForUnknownProperty() 
    {
        // Test for an unknown property
        $result = $this->account->__get('unknown_property');
        $this->assertNull($result);
    }

    public function testMagicGetCallsLogoutWhenCalled() 
    {
        // Setting a mock for the session
        $this->mockSession->expects($this->once())
            ->method('remove')
            ->with('user');
            
        $this->mockApp->session = $this->mockSession;
        
        // Before calling logout we set the user
        $this->mockApp->user = $this->mockUser;
        
        // Performing the magical method
        $result = $this->account->__get('logout');
        
        // Checking that the user has been removed from the session
        $this->assertNull($this->mockApp->user);
    }

    public function testLoginSetsUserAndSessionCorrectly() 
    {
        // Data preparation
        $userId = 456;
        $this->mockUser->method('primaryKey')->willReturn('id');
        $this->mockUser->id = $userId;
        
        // Expectations
        $this->mockSession->expects($this->once())
            ->method('set')
            ->with('user', $userId);
            
        $this->mockApp->session = $this->mockSession;
        
        // Execution of the login method
        $result = $this->account->login($this->mockUser);
        
        // Verification
        $this->assertTrue($result);
        $this->assertEquals($this->mockUser, Atom::$app->user);
    }

    public function testLogoutWithNoSession() 
    {
        // User setting
        $this->mockApp->user = $this->mockUser;
        
        // Executing the logout method without a session (a session is set)
        $this->account->logout();
        
        // Checking that the user has been removed from the session
        $this->assertNull($this->mockApp->user);
    }

    public function testIsGuestWithDifferentUserStates() 
    {
        // Guest test (no user)
        $this->mockApp->user = null;
        $this->assertTrue(Account::isGuest());
        
        // Test for logged in user
        $this->mockApp->user = $this->mockUser;
        $this->assertFalse(Account::isGuest());
    }

    public function testLoginWithDifferentUserProperties() 
    {
        // Test with a different primary key
        $this->mockUser->method('primaryKey')->willReturn('userId');
        $this->mockUser->userId = 789;
        
        $this->mockSession->expects($this->once())
            ->method('set')
            ->with('user', 789);
            
        $this->mockApp->session = $this->mockSession;
        
        // Execution of the login method
        $result = $this->account->login($this->mockUser);
        
        $this->assertTrue($result);
    }
}
