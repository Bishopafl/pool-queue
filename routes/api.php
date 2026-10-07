<?php

use App\Http\Controllers\Api\VisionController;
use Illuminate\Support\Facades\Route;

// The pool-vision camera. Tokens come from `php artisan pool-vision:token`.
Route::middleware(['auth:sanctum', 'abilities:vision', 'throttle:120,1'])
    ->prefix('vision')
    ->group(function () {
        Route::get('/game', [VisionController::class, 'game'])->name('api.vision.game');
        Route::post('/events', [VisionController::class, 'storeEvent'])->name('api.vision.events');
    });
