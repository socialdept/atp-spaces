<?php

namespace SocialDept\AtpSpaces\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SocialDept\AtpClient\AtpClientServiceProvider;
use SocialDept\AtpSpaces\AtpSpacesServiceProvider;
use SocialDept\AtpSupport\AtpSupportServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AtpSupportServiceProvider::class,
            AtpClientServiceProvider::class,
            AtpSpacesServiceProvider::class,
        ];
    }
}
