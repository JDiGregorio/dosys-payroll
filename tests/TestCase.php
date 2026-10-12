<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new \RuntimeException('REFUSING TO RUN TESTS: cached Laravel config may point to a development or production database. Run artisan config:clear first.');
        }

        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $connection === 'mysql'
            ? $app['db']->connection('mysql')->getDatabaseName()
            : null;

        if ($app->environment() !== 'testing' || $connection !== 'mysql'
            || $database !== 'planilla_dosys_testing') {
            throw new \RuntimeException(sprintf(
                'REFUSING TO RUN TESTS: database "%s" on connection "%s" is not the isolated testing database.',
                $database ?? '(unknown)',
                $connection ?? '(unknown)',
            ));
        }

        return $app;
    }
}
