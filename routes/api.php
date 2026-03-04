<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Vehicle\VehicleController;
use App\Http\Controllers\MaintenanceRequest\MaintenanceRequestController;
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


    Route::prefix('vehicles')->middleware('auth:sanctum')->group(function () {
        Route::get('/', [VehicleController::class, 'index']);                
        Route::post('/', [VehicleController::class, 'store']);               
        Route::get('/{id}', [VehicleController::class, 'show']);             
        Route::put('/{id}', [VehicleController::class, 'update']);           
        Route::delete('/{id}', [VehicleController::class, 'destroy']);       
        
        Route::get('/{id}/maintenance', [VehicleController::class, 'maintenanceHistory']); 
        Route::get('/{id}/fuel-logs', [VehicleController::class, 'fuelLogs']);            
        Route::get('/{id}/alerts', [VehicleController::class, 'alerts']);                   
    });


     Route::prefix('maintenance-requests')->middleware('auth:sanctum')->group(function () {

        Route::get('/', [MaintenanceRequestController::class, 'index']);
        Route::post('/', [MaintenanceRequestController::class, 'store']);
        Route::get('/{id}', [MaintenanceRequestController::class, 'show']);
        Route::put('/{id}', [MaintenanceRequestController::class, 'update']);
        Route::delete('/{id}', [MaintenanceRequestController::class, 'destroy']);
        
        Route::get('/filter/pending', [MaintenanceRequestController::class, 'pending']);
        Route::get('/filter/accepted', [MaintenanceRequestController::class, 'accepted']);
        Route::get('/filter/completed', [MaintenanceRequestController::class, 'completed']);
        
        Route::post('/{id}/cancel', [MaintenanceRequestController::class, 'cancel']);
        Route::post('/{id}/accept-quotation/{quotationId}', [MaintenanceRequestController::class, 'acceptQuotation']);
    });
