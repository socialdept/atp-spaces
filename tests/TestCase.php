<?php

namespace SocialDept\AtpSpaces\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SocialDept\AtpSpaces\AtpSpacesServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AtpSpacesServiceProvider::class,
        ];
    }
}
