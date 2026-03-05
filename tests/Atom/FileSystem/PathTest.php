<?php

namespace Tests\Atom\FileSytem;

use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    private $originalServerHome;
    private $originalServerHomdrive;
    private $originalServerHompath;

    protected function setUp(): void
    {
        parent::setUp();
        // Store original server variables
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $this->originalServerHomdrive = $_SERVER['HOMEDRIVE'] ?? null;
        $this->originalServerHompath = $_SERVER['HOMEPATH'] ?? null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Restore original server variables
        $_SERVER['HOME'] = $this->originalServerHome;
        $_SERVER['HOMEDRIVE'] = $this->originalServerHomdrive;
        $_SERVER['HOMEPATH'] = $this->originalServerHompath;
    }
}
