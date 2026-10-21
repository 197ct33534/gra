<?php

namespace Tests;

use Database\Seeders\TestFixtureSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Test dùng bộ dữ liệu cố định; DatabaseSeeder là dữ liệu demo cho chủ gara và có thể đổi theo kịch bản demo.
     *
     * @param  array<int, class-string>|class-string  $class
     */
    public function seed($class = TestFixtureSeeder::class)
    {
        return parent::seed($class);
    }
}
