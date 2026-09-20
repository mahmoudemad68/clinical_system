<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $dir = storage_path('framework/testing/objects');
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
