<?php

namespace Enadstack\ImporterExporter;

use Illuminate\Support\ServiceProvider;

class ImporterExporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/importer-exporter.php', 'importer-exporter');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/importer-exporter.php' => config_path('importer-exporter.php'),
        ], 'importer-exporter-config');

        $this->publishes([
            __DIR__.'/../stubs/templates' => base_path('stubs/importer-exporter/templates'),
        ], 'importer-exporter-templates');
    }
}