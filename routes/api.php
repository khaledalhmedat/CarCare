<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Vehicle\VehicleController;
use App\Http\Controllers\MaintenanceRequest\MaintenanceRequestController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Technician\TechnicianController;
use App\Http\Controllers\Technician\TechnicianMaintenanceController;
use App\Http\Controllers\FuelOrder\FuelOrderController;
use App\Http\Controllers\CarwashBooking\CarwashBookingController;
use App\Http\Controllers\CarWasher\CarWasherController;




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



Route::prefix('fuel-orders')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [FuelOrderController::class, 'index']);
    Route::post('/', [FuelOrderController::class, 'store']);
    Route::get('/{id}', [FuelOrderController::class, 'show']);
    Route::post('/{id}/cancel', [FuelOrderController::class, 'cancel']);
});


Route::middleware('auth:sanctum')->prefix('customer')->group(function () {

    Route::get('/car_washers', [CarwashBookingController::class, 'availableCarWashers']);

    Route::get('/car_washers/{id}', [CarwashBookingController::class, 'showCarWasher']);

    Route::post('/carwash_bookings', [CarwashBookingController::class, 'store']);

    Route::get('/carwash_bookings', [CarwashBookingController::class, 'index']);

    Route::get('/carwash_bookings/{id}', [CarwashBookingController::class, 'show']);

    Route::post('/carwash_bookings/{id}/cancel', [CarwashBookingController::class, 'cancel']);

    Route::post('/carwash_bookings/{id}/rate', [CarwashBookingController::class, 'rateCarWasher']);

    Route::get('/car_washers/{id}/ratings', [CarwashBookingController::class, 'carWasherRatings']);
});

Route::middleware(['auth:sanctum'])->prefix('car_washer')->group(function () {


    Route::get('/statistics', [CarWasherController::class, 'statistics']);

    Route::get('/my_profile', [CarWasherController::class, 'myProfile']);

    Route::post('/profile', [CarWasherController::class, 'storeOrUpdateProfile']);

    Route::post('/profile/logo', [CarWasherController::class, 'uploadLogo']);
    Route::delete('/profile/logo', [CarWasherController::class, 'deleteLogo']);

    Route::get('/my_bookings', [CarWasherController::class, 'myBookings']);
    Route::post('/bookings/{id}/accept', [CarWasherController::class, 'acceptBooking']);
    Route::post('/bookings/{id}/reject', [CarWasherController::class, 'rejectBooking']);
    Route::patch('/bookings/{id}/status', [CarWasherController::class, 'updateBookingStatus']);

    Route::patch('/availability', [CarWasherController::class, 'updateAvailability']);
});
