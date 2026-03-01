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
    Route::get('/', [ProfileController::class, 'show']);              
    Route::put('/', [ProfileController::class, 'update']);           
    Route::post('/password', [ProfileController::class, 'updatePassword']); 
    Route::post('/avatar', [ProfileController::class, 'updateAvatar']);     
    Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']);   
    Route::delete('/', [ProfileController::class, 'deleteAccount']);        
});