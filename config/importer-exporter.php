<?php

return [
    'disk' => env('IMPORTER_EXPORTER_DISK', config('filesystems.default', 'local')),
    'route_prefix' => 'ie',          // /ie/template/{type}, /ie/import
    'route_middleware' => ['web','auth'],

    // register types here later (type => class)
    'importers' => [
    ],
    'exporters' => [

    ],
];