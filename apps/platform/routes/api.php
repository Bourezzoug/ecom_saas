<?php

use App\Http\Controllers\Api\ConnectorApiController;
use Illuminate\Support\Facades\Route;

/*
| WordPress connector plugin → platform (Sanctum connection key).
*/
Route::prefix('connector/v1')
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->group(function () {
        Route::post('handshake', [ConnectorApiController::class, 'handshake'])->name('connector.handshake');
        Route::get('status', [ConnectorApiController::class, 'status'])->name('connector.status');
        Route::post('disconnect', [ConnectorApiController::class, 'disconnect'])->name('connector.disconnect');
    });
