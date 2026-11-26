<?php

declare(strict_types=1);

namespace Tests\Atom\FileSystem;

use PHPUnit\Framework\TestCase;
use Atom\FileSyTem\Path;

/**
 * Test suite for the Path component.
 */
class PathTest extends TestCase
{
    /**
     * Test normalize.
     */
    public function testNormalize()
    {
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::normalize('/home/user'));
        $this->assertEquals('\\home\\user', \Atom\FileSyTem\Path::normalize('\\home\\user'));
        $this->assertEquals('/', \Atom\FileSyTem\Path::normalize('/'));
    }

    /**
     * Test canonicalize with empty string.
     */
    public function testCanonicalizeWithEmptyString()
    {
        $this->assertEquals('', \Atom\FileSyTem\Path::canonicalize(''));
    }

    /**
     * Test canonicalize with root only.
     */
    public function testCanonicalizeWithRootOnly()
    {
        $this->assertEquals('/', \Atom\FileSyTem\Path::canonicalize('/'));
    }

    /**
     * Test canonicalize with simple path.
     */
    public function testCanonicalizeWithSimplePath()
    {
        $this->assertEquals('/home/user', \Atom\FileSyTem\Path::canonicalize('/home/user'));
    }

    /**
     * Test buffering.
     */
    public function testBuffering()
    {
        // Test that the buffer works correctly by calling the same path twice
        $path1 = \Atom\FileSyTem\Path::canonicalize('/home/user');
        $path2 = \Atom\FileSyTem\Path::canonicalize('/home/user');
        
        // Should be the same result
        $this->assertEquals($path1, $path2);
    }
}
