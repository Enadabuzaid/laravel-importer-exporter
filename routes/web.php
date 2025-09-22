<?php

use Illuminate\Support\Facades\Route;
use Enadstack\ImporterExporter\Http\Controllers\ImportExportController;

Route::middleware(config('importer-exporter.route_middleware', ['web','auth']))
    ->prefix(config('importer-exporter.route_prefix', 'ie'))
    ->group(function () {
        // templates & import/export
        Route::get('/template/{type}', [ImportExportController::class, 'template'])->name('ie.template');
        Route::post('/import',           [ImportExportController::class, 'import'])->name('ie.import');
        Route::get('/export/{type}',     [ImportExportController::class, 'export'])->name('ie.export');
        // logs
        Route::get('/files',             [ImportExportController::class, 'files'])->name('ie.files');
        Route::get('/files/{id}',        [ImportExportController::class, 'show'])->name('ie.files.show');
        Route::get('/files/{id}/download', [ImportExportController::class, 'download'])
            ->name('ie.files.download');
    });