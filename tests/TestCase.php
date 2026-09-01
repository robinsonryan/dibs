<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migrator;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;
use PDOException;
use RobinsonRyan\Dibs\DibsServiceProvider;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Organization;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\Room;
use RobinsonRyan\Dibs\Tests\Fixtures\Models\User;

abstract class TestCase extends Orchestra
{
    private static bool $databaseEnsured = false;

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
        // A checkout nested at .claude/worktrees/<slug>/ gets its own
        // testing_wt_<slug> database automatically (created on first run), so
        // parallel worktrees never share a schema. DIBS_TEST_DB_* override all.
        $connection = [
            'driver' => 'pgsql',
            'host' => env('DIBS_TEST_DB_HOST', 'db'),
            'port' => (int) env('DIBS_TEST_DB_PORT', 5432),
            'database' => $this->resolveDatabaseName(),
            'username' => env('DIBS_TEST_DB_USERNAME', 'db'),
            'password' => env('DIBS_TEST_DB_PASSWORD', 'db'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];

        $this->ensureDatabaseExists($connection);

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

    private function resolveDatabaseName(): string
    {
        $override = env('DIBS_TEST_DB_DATABASE');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        if (preg_match('#/\.claude/worktrees/([^/]+)/#', __DIR__, $matches) === 1) {
            return 'testing_wt_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($matches[1]));
        }

        return 'testing';
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function ensureDatabaseExists(array $connection): void
    {
        if (self::$databaseEnsured) {
            return;
        }

        self::$databaseEnsured = true;

        $name = (string) $connection['database'];
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=postgres', $connection['host'], $connection['port']),
            (string) $connection['username'],
            (string) $connection['password'],
        );

        $statement = $pdo->prepare('select 1 from pg_database where datname = ?');
        $statement->execute([$name]);

        if ($statement->fetchColumn() !== false) {
            return;
        }

        try {
            $pdo->exec(sprintf('CREATE DATABASE "%s"', $name));
        } catch (PDOException) {
            // Another process created it between the check and the create.
        }
    }
}
