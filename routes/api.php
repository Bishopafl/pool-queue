<?php

use App\Http\Controllers\Api\VisionController;
use App\Http\Controllers\Api\VisionPairController;
use Illuminate\Support\Facades\Route;

// Pool Vision cameras (the phone site at poolvision.adamlopez.co, or the laptop app).
// A phone trades VISION_PAIR_PASSWORD for its token; `php artisan pool-vision:token` also issues one.
// Browsers may call these only from VISION_ALLOWED_ORIGINS (config/cors.php).
Route::post('/vision/pair', VisionPairController::class)->middleware('throttle:5,1')->name('api.vision.pair');

Route::middleware(['auth:sanctum', 'abilities:vision', 'throttle:120,1'])
    ->prefix('vision')
    ->group(function () {
        Route::get('/game', [VisionController::class, 'game'])->name('api.vision.game');
        Route::post('/events', [VisionController::class, 'storeEvent'])->name('api.vision.events');
    });
