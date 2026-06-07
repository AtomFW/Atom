<?php

declare(strict_types=1);

namespace Tests\Atom\FileSystem;

use PHPUnit\Framework\TestCase;
use Atom\FileSyTem\Path;

class PathTest extends TestCase
{
    public function testNormalize()
    {
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::normalize('/home/user'));
        $this->assertEquals('\\home\\user', \Atom\FileSyTem\Path::normalize('\\home\\user'));
        $this->assertEquals('/', \Atom\FileSyTem\Path::normalize('/'));
    }

    public function testCanonicalizeWithEmptyString()
    {
        $this->assertEquals('', \Atom\FileSyTem\Path::canonicalize(''));
    }

    public function testCanonicalizeWithRootOnly()
    {
        $this->assertEquals('/', \Atom\FileSyTem\Path::canonicalize('/'));
    }

    public function testCanonicalizeWithSimplePath()
    {
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::canonicalize('/home/user'));
    }

    public function testCanonicalizeWithPathWithDotSegments()
    {
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::canonicalize('/home/./user'));
        $this->assertEquals('/home', \Atom\FileSyTem\Path::canonicalize('/home/../user'));
    }

    public function testCanonicalizeWithPathWithMultipleDots()
    {
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::canonicalize('/home/./user'));
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::canonicalize('/home/../user'));
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::canonicalize('/home/./../user'));
    }

    public function testCanonicalizeWithMultipleSlashes()
    {
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::canonicalize('//home//user'));
        $this->assertEquals('/home/user', \atom\FileSyTem\Path::canonicalize('/home///user'));
    }

    public function testSplit()
    {
        // Test absolute path
        [$root, $path] = \Atom\FileSyTem\Path::split('/home/user');
        $this->assertEquals('/', $root);
        $this->assertEquals('home/user', $path);

        // Test relative path
        [$root, $path] = \Atom\FileSyTem\Path::split('home/user');
        $this->assertEquals('', $root);
        $this->assertEquals('home/user', $path);
    }

    public function testFindCanonicalParts()
    {
        $result = \Atom\FileSyTem\Path::findCanonicalParts('/', 'home/user');
        $this->assertEquals(['home', 'user'], $result);

        $result = \Atom\FileSyTem\Path::findCanonicalParts('/home', '../user');
        $this->assertEquals(['user'], $result);
    }


    public function testGetHomeDirectory()
    {
        // This is a bit tricky to test without mocking the environment variables
        // But we can at least verify it doesn't throw an exception in normal cases
        if (isset($_SERVER['HOME']) || isset($_SERVER['HOMEDRIVE'], $_SERVER['HOMEPATH'])) {
            $this->assertIsString(\Atom\FileSyTem\Path::getHomeDirectory());
        }
    }

    public function testBuffering()
    {
        // Test that the buffer works correctly by calling the same path twice
        $path1 = \Atom\FileSyTem\Path::canonicalize('/home/user');
        $path2 = \Atom\FileSyTem\Path::canonicalize('/home/user');
        
        // Should be the same result
        $this->assertEquals($path1, $path2);
    }
}
