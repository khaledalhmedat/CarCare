<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Profile\ProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});




Route::prefix('profile')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);              // GET /api/profile
    Route::put('/', [ProfileController::class, 'update']);            // PUT /api/profile
    Route::post('/password', [ProfileController::class, 'updatePassword']); // POST /api/profile/password
    Route::post('/avatar', [ProfileController::class, 'updateAvatar']);     // POST /api/profile/avatar
    Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']);   // DELETE /api/profile/avatar
    Route::delete('/', [ProfileController::class, 'deleteAccount']);        // DELETE /api/profile
});