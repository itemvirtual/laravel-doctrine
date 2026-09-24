<?php

namespace Itemvirtual\LaravelDoctrine;

use Illuminate\Support\ServiceProvider;
use Itemvirtual\LaravelDoctrine\Console\Commands\DoctrineGenerateMappings;
use Itemvirtual\LaravelDoctrine\Console\Commands\DoctrineUpdate;
use Itemvirtual\LaravelDoctrine\Console\Commands\DoctrineValidate;
use Itemvirtual\LaravelDoctrine\Console\Commands\MigrationsGenerate;

class LaravelDoctrineServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {

        // Only for console
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('laravel-doctrine.php'),
            ], 'config');

            $this->publishes([
                __DIR__ . '/../database/doctrine/xml-mappings/' => config('laravel-doctrine.xml_mappings_path')
            ], 'laravel_default_migrations');

            // add a logging channel to save executed queries
            if (config('laravel-doctrine.logging', null) && config('laravel-doctrine.save_logs', null)) {
                $this->app->make('config')->set('logging.channels.laravel-doctrine', config('laravel-doctrine.logging'));
            }

            // Registering package commands.
            $this->commands([
                DoctrineUpdate::class,
                DoctrineValidate::class,
                DoctrineGenerateMappings::class,
                // generate migrations
                MigrationsGenerate::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // This will allow your users to define only the options they actually
        // want to override in the published copy of the configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'laravel-doctrine');

    }

}
