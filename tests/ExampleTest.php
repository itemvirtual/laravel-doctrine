<?php

namespace Itemvirtual\LaravelDoctrine\Tests;

use Orchestra\Testbench\TestCase;
use Itemvirtual\LaravelDoctrine\LaravelDoctrineServiceProvider;

class ExampleTest extends TestCase
{

    protected function getPackageProviders($app)
    {
        return [LaravelDoctrineServiceProvider::class];
    }
    
    /** @test */
    public function true_is_true()
    {
        $this->assertTrue(true);
    }
}
