<?php

namespace MigrationSquash;

use Illuminate\Support\ServiceProvider;
use MigrationSquash\Console\Commands\MigrateSquashCommand;
use MigrationSquash\Console\Commands\MigrateSquashRestoreCommand;

class MigrationSquashServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/migrationsquash.php',
            'migrationsquash',
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateSquashCommand::class,
                MigrateSquashRestoreCommand::class,
            ]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/Generation/Stubs/squashed-table.stub' => base_path('stubs/migration-squash/squashed-table.stub'),
                __DIR__.'/Generation/Stubs/squashed-foreign-keys.stub' => base_path('stubs/migration-squash/squashed-foreign-keys.stub'),
            ], 'migration-squash-stubs');

            $this->publishes([
                __DIR__.'/../../config/migrationsquash.php' => config_path('migrationsquash.php'),
            ], 'migrationsquash-config');
        }
    }
}
