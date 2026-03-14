<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Vehicle\VehicleController;
use App\Http\Controllers\MaintenanceRequest\MaintenanceRequestController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Technician\TechnicianController;
use App\Http\Controllers\Technician\TechnicianMaintenanceController;



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

    Route::get('/statistics', [MaintenanceRequestController::class, 'statistics']);


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


    Route::get('/{id}/quotations', [MaintenanceRequestController::class, 'quotations']);

    Route::get('/{id}/accepted-quotation', [MaintenanceRequestController::class, 'acceptedQuotation']);

    Route::post('/quotations/{quotationId}/reject', [MaintenanceRequestController::class, 'rejectQuotation']);

    Route::post('/{id}/accept-quick/{quotationId}', [MaintenanceRequestController::class, 'acceptQuotationQuick']);

    Route::post('/{id}/reopen', [MaintenanceRequestController::class, 'reopenRequest']);
});

Route::post('/rate-job/{jobId}', [MaintenanceRequestController::class, 'rateService'])->middleware('auth:sanctum');

Route::prefix('technician')->middleware('auth:sanctum')->group(function () {

    Route::get('/statistics', [TechnicianMaintenanceController::class, 'statistics']);


    Route::get('/profile', [TechnicianController::class, 'profile']);
    Route::post('/profile', [TechnicianController::class, 'updateProfile']);
    Route::put('/profile', [TechnicianController::class, 'updateProfile']);
    Route::patch('/availability', [TechnicianController::class, 'updateAvailability']);

    Route::get('/available-requests', [TechnicianMaintenanceController::class, 'availableRequests']);
    Route::get('/requests/{id}', [TechnicianMaintenanceController::class, 'showRequest']);
    Route::post('/requests/{id}/quotation', [TechnicianMaintenanceController::class, 'submitQuotation']);

    Route::get('/my-jobs', [TechnicianMaintenanceController::class, 'myJobs']);
    Route::get('/my-jobs/accepted', [TechnicianMaintenanceController::class, 'myAcceptedJobs']);
    Route::patch('/jobs/{id}/status', [TechnicianMaintenanceController::class, 'updateJobStatus']);

    Route::get('/my-jobs/completed', [TechnicianMaintenanceController::class, 'myCompletedJobs']);


    Route::get('/jobs/{id}', [TechnicianMaintenanceController::class, 'showJob']);

    Route::post('/jobs/{id}/report', [TechnicianMaintenanceController::class, 'addMaintenanceReport']);
});

Route::prefix('debug')->middleware('auth:sanctum')->group(function () {
    Route::get('/technician-jobs', [TechnicianMaintenanceController::class, 'debugJobs']);
    Route::get('/technician-all-jobs', [TechnicianMaintenanceController::class, 'allMyJobs']);
});
