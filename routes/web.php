<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\QueueController;
use Illuminate\Support\Facades\Route;

Route::get('/', [QueueController::class, 'index'])->name('queue.index');

Route::post('/queue', [QueueController::class, 'store'])->name('queue.store');
Route::post('/queue/{entry}/move', [QueueController::class, 'move'])->name('queue.move');
Route::post('/queue/{entry}/start', [QueueController::class, 'start'])->name('queue.start');
Route::delete('/queue/{entry}', [QueueController::class, 'destroy'])->name('queue.destroy');

Route::get('/games', [GameController::class, 'index'])->name('games.index');
Route::get('/games/create', [GameController::class, 'create'])->name('games.create');
Route::post('/games', [GameController::class, 'store'])->name('games.store');
Route::get('/games/{game}', [GameController::class, 'show'])->name('games.show');
Route::patch('/games/{game}', [GameController::class, 'update'])->name('games.update');
Route::post('/games/{game}/score', [GameController::class, 'score'])->name('games.score');
Route::post('/games/{game}/finish', [GameController::class, 'finish'])->name('games.finish');
Route::delete('/games/{game}', [GameController::class, 'destroy'])->name('games.destroy');

Route::get('/players', [PlayerController::class, 'index'])->name('players.index');
Route::post('/players', [PlayerController::class, 'store'])->name('players.store');
Route::patch('/players/{player}', [PlayerController::class, 'update'])->name('players.update');
Route::delete('/players/{player}', [PlayerController::class, 'destroy'])->name('players.destroy');

Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');
