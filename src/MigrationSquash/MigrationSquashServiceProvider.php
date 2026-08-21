<?php

namespace MigrationSquash;

use Illuminate\Support\ServiceProvider;
use MigrationSquash\Console\Commands\MigrateSquashCommand;

class MigrationSquashServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Publish configuration if needed
        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateSquashCommand::class,
            ]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish stubs
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/Generation/Stubs/squashed-table.stub' => 
                    base_path('stubs/migration-squash/squashed-table.stub'),
            ], 'migration-squash-stubs');
            
            // Publish config file
            $this->publishes([
                __DIR__ . '/../../config/migrationsquash.php' => 
                    config_path('migrationsquash.php'),
            ], 'migrationsquash-config');
        }
    }
}
