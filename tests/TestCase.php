<?php

namespace Tests;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // Check the resolved connection (including DB_URL) before migration traits
        // can rebuild anything. Test commands must never target application data.
        if (!$app->environment('testing') || $app->make('db')->connection()->getDatabaseName() !== 'hmis_test') {
            throw new \RuntimeException('Feature tests require APP_ENV=testing and the separate hmis_test database.');
        }
        return $app;
    }
}
