<?php

namespace Tests;

use App\Support\DestructiveDatabaseCommandGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected $defaultHeaders = ['Accept-Language' => 'ja'];

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $settings = $app['config'];
        $connection = $settings->get('database.default');
        $profile = $settings->get('database-safety.profile');
        DestructiveDatabaseCommandGuard::assertTestConnection(
            (string) $settings->get('app.env'),
            $settings->get('database.connections.'.$connection, []),
            $settings->get('database-safety.profiles.'.$profile, []),
        );

        return $app;
    }
}
