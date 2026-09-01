<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migrator;
use Orchestra\Testbench\TestCase as Orchestra;
use RobinsonRyan\Dibs\DibsServiceProvider;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Organization;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Room;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\User;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DibsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The schema uses PostgreSQL's native uuidv7() as a column default, which
        // SQLite cannot express — so the suite runs against DDEV's Postgres on a
        // database of its own. Do not "simplify" this to SQLite.
        //
        // DIBS_TEST_DB_* overrides are how a worktree gets its own
        // testing_wt_<slug> database.
        $connection = [
            'driver' => 'pgsql',
            'host' => env('DIBS_TEST_DB_HOST', 'db'),
            'port' => (int) env('DIBS_TEST_DB_PORT', 5432),
            'database' => env('DIBS_TEST_DB_DATABASE', 'testing'),
            'username' => env('DIBS_TEST_DB_USERNAME', 'db'),
            'password' => env('DIBS_TEST_DB_PASSWORD', 'db'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);
        // A second session on the same database, for concurrency tests that need
        // two transactions contending for one row.
        $app['config']->set('database.connections.testing_b', $connection);

        // Fixture (consumer stand-in) migrations, registered as a path rather
        // than via loadMigrationsFrom() so Testbench does not rebuild per test.
        $app->afterResolving('migrator', function (Migrator $migrator): void {
            $migrator->path(__DIR__.'/Fixtures/database/migrations');
        });

        // Consumers usually alias their morph classes; the package must store
        // and resolve aliases, never assume FQCNs.
        Relation::morphMap([
            'user' => User::class,
            'room' => Room::class,
            'organization' => Organization::class,
        ]);
    }
}
