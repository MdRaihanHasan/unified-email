<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sandbox has no built Vite manifest; feature tests only assert Inertia data.
        $this->withoutVite();
    }
}
