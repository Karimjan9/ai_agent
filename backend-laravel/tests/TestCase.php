<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        // RefreshDatabase is destructive. Refuse to let a misconfigured test
        // process reach the migration step against a real application DB.
        if (config('app.env') !== 'testing' || config('database.default') !== 'sqlite') {
            throw new \RuntimeException('Tests require APP_ENV=testing and DB_CONNECTION=sqlite.');
        }

        return parent::setUpTraits();
    }
}
