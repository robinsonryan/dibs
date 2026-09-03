<?php

declare(strict_types=1);

namespace RobinsonRyan\Dibs;

use Illuminate\Support\ServiceProvider;
use RobinsonRyan\Dibs\Contracts\HostResolver;
use RobinsonRyan\Dibs\Support\IdentityHostResolver;

final class DibsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dibs.php', 'dibs');

        // bind(), not singleton(): a consumer that pools positions rather than
        // people replaces this with its own binding, and nothing in the package
        // holds on to the instance between calls.
        $this->app->bind(HostResolver::class, IdentityHostResolver::class);
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->loadMigrations();
        $this->publishMigrations();
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/dibs.php' => config_path('dibs.php'),
        ], 'dibs-config');
    }

    /**
     * Load the package's migrations unless the consumer has published them —
     * publishing means they took ownership, and loading them too runs each
     * migration twice.
     */
    private function loadMigrations(): void
    {
        if (! $this->migrationsPublished()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    private function publishMigrations(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'dibs-migrations');
    }

    private function migrationsPublished(): bool
    {
        return count(glob(database_path('migrations/*_create_dibs_*.php')) ?: []) > 0;
    }
}
