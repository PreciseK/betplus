<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'Betplus Platform API',
        'status' => 'operational',
        'version' => 'v1',
        'timestamp' => now()->toIso8601String(),
    ]);
});
