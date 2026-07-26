<?php

use App\Http\Controllers\AdvertisementController as PublicAdvertisementController;
use App\Http\Controllers\Admin\AdvertisementController;
use App\Http\Controllers\Admin\BillingSettingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProviderApprovalController;
use App\Http\Controllers\Admin\ProviderBillingStatusController;
use App\Http\Controllers\Admin\ProviderInvoiceController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\CarwashBooking\CarwashBookingController;
use App\Http\Controllers\CarWasher\CarWasherController;
use App\Http\Controllers\CustomerOrder\CustomerOrderController;
use App\Http\Controllers\CustomerShop\CustomerShopController;
use App\Http\Controllers\FuelOrder\FuelOrderController;
use App\Http\Controllers\FuelProvider\FuelProviderController;
use App\Http\Controllers\FuelProviderOrder\FuelProviderOrderController;
use App\Http\Controllers\MaintenanceRequest\MaintenanceRequestController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Shop\ShopController;
use App\Http\Controllers\Sos\SosController;
use App\Http\Controllers\Technician\TechnicianController;
use App\Http\Controllers\Technician\TechnicianMaintenanceController;
use App\Http\Controllers\TechnicianSos\TechnicianSosController;
use App\Http\Controllers\Tracking\TrackingController;
use App\Http\Controllers\Vehicle\VehicleController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

// Public: currently-live advertisements for the mobile app (unauthenticated, like /health)
Route::get('/advertisements/active', [PublicAdvertisementController::class, 'active']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/dashboard')->group(function () {
    Route::get('/summary', [DashboardController::class, 'summary']);
    Route::get('/operations', [DashboardController::class, 'operations']);
    Route::get('/revenue', [DashboardController::class, 'revenue']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/reports')->group(function () {
    Route::get('/overview', [ReportController::class, 'overview']);
    Route::get('/operations', [ReportController::class, 'operations']);
    Route::get('/providers', [ReportController::class, 'providers']);
    Route::get('/financial', [ReportController::class, 'financial']);
    Route::get('/billing', [ReportController::class, 'billing']);
    Route::get('/advertisements', [ReportController::class, 'advertisements']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/advertisements')->group(function () {
    Route::get('/', [AdvertisementController::class, 'index']);
    Route::post('/', [AdvertisementController::class, 'store']);
    Route::get('/{id}', [AdvertisementController::class, 'show']);
    // POST (optionally with _method=PUT) required to replace the image — PHP cannot
    // parse multipart/form-data on a real PUT request. PUT is accepted for JSON-only edits.
    Route::match(['put', 'post'], '/{id}', [AdvertisementController::class, 'update']);
    Route::delete('/{id}', [AdvertisementController::class, 'destroy']);
    Route::post('/{id}/activate', [AdvertisementController::class, 'activate']);
    Route::post('/{id}/deactivate', [AdvertisementController::class, 'deactivate']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/billing')->group(function () {
    // Billing settings
    Route::get('/settings', [BillingSettingController::class, 'index']);
    Route::post('/settings', [BillingSettingController::class, 'store']);
    Route::get('/settings/{id}', [BillingSettingController::class, 'show']);
    Route::put('/settings/{id}', [BillingSettingController::class, 'update']);
    Route::delete('/settings/{id}', [BillingSettingController::class, 'destroy']);

    // Provider billing status (dashboard) — declared before /invoices/{id} to avoid capture
    Route::get('/provider-status', [ProviderBillingStatusController::class, 'index']);

    // Provider invoices
    Route::get('/invoices', [ProviderInvoiceController::class, 'index']);
    Route::post('/invoices/generate', [ProviderInvoiceController::class, 'generate']);
    Route::get('/invoices/{id}', [ProviderInvoiceController::class, 'show']);
    Route::post('/invoices/{id}/issue', [ProviderInvoiceController::class, 'issue']);
    Route::post('/invoices/{id}/mark-paid', [ProviderInvoiceController::class, 'markPaid']);
    Route::post('/invoices/{id}/cancel', [ProviderInvoiceController::class, 'cancel']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/provider-approvals')->group(function () {
    Route::get('/{type}', [ProviderApprovalController::class, 'index']);
    Route::get('/{type}/{id}', [ProviderApprovalController::class, 'show']);
    Route::post('/{type}/{id}/approve', [ProviderApprovalController::class, 'approve']);
    Route::post('/{type}/{id}/reject', [ProviderApprovalController::class, 'reject']);
    Route::post('/{type}/{id}/suspend', [ProviderApprovalController::class, 'suspend']);
    Route::post('/{type}/{id}/reactivate', [ProviderApprovalController::class, 'reactivate']);
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

    Route::get('/profile', [TechnicianController::class, 'profile']);
    Route::post('/profile', [TechnicianController::class, 'updateProfile']);
    Route::put('/profile', [TechnicianController::class, 'updateProfile']);

    Route::middleware('role:technician')->group(function () {
        Route::get('/statistics', [TechnicianMaintenanceController::class, 'statistics']);

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

    Route::get('/my_profile', [CarWasherController::class, 'myProfile']);
    Route::post('/profile', [CarWasherController::class, 'storeOrUpdateProfile']);

    Route::middleware('role:car-washer')->group(function () {
        Route::get('/statistics', [CarWasherController::class, 'statistics']);

        Route::post('/profile/logo', [CarWasherController::class, 'uploadLogo']);
        Route::delete('/profile/logo', [CarWasherController::class, 'deleteLogo']);

        Route::get('/my_bookings', [CarWasherController::class, 'myBookings']);
        Route::post('/bookings/{id}/accept', [CarWasherController::class, 'acceptBooking']);
        Route::post('/bookings/{id}/reject', [CarWasherController::class, 'rejectBooking']);
        Route::patch('/bookings/{id}/status', [CarWasherController::class, 'updateBookingStatus']);

        Route::patch('/availability', [CarWasherController::class, 'updateAvailability']);
    });
});

Route::middleware('auth:sanctum')->prefix('sos')->group(function () {
    Route::get('/', [SosController::class, 'index']);
    Route::post('/', [SosController::class, 'store']);
    Route::get('/{id}', [SosController::class, 'show']);
    Route::post('/{id}/cancel', [SosController::class, 'cancel']);
});

Route::middleware(['auth:sanctum', 'role:technician'])->prefix('technician/sos')->group(function () {

    Route::get('/available', [TechnicianSosController::class, 'availableRequests']);

    Route::get('/requests/{id}', [TechnicianSosController::class, 'showRequest']);

    Route::post('/requests/{id}/accept', [TechnicianSosController::class, 'acceptRequest']);

    Route::patch('/requests/{id}/status', [TechnicianSosController::class, 'updateStatus']);

    Route::get('/my_requests', [TechnicianSosController::class, 'myRequests']);

    Route::get('/statistics', [TechnicianSosController::class, 'statistics']);

    Route::post('/requests/{id}/cancel', [TechnicianSosController::class, 'cancelRequest']);
});

Route::middleware(['auth:sanctum', 'role:technician'])->group(function () {
    Route::post('/sos/{id}/location', [TrackingController::class, 'shareLocation']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/sos/{id}/track', [TrackingController::class, 'trackTechnician']);
});

Route::middleware(['auth:sanctum', 'role:technician'])->prefix('technician')->group(function () {
    Route::post('/location', [TechnicianController::class, 'updateLocation']);
});

// routes/api.php

Route::middleware(['auth:sanctum'])->prefix('fuel_provider')->group(function () {
    Route::get('/my_profile', [FuelProviderController::class, 'myProfile']);
    Route::post('/profile', [FuelProviderController::class, 'storeOrUpdateProfile']);
    Route::put('/profile', [FuelProviderController::class, 'storeOrUpdateProfile']);

    Route::middleware('role:fuel-provider')->group(function () {
        Route::patch('/availability', [FuelProviderController::class, 'updateAvailability']);
        Route::post('/prices', [FuelProviderController::class, 'updatePrices']);
        Route::get('/statistics', [FuelProviderController::class, 'statistics']);

        Route::get('/available_orders', [FuelProviderOrderController::class, 'availableOrders']);
        Route::get('/orders/{id}', [FuelProviderOrderController::class, 'showOrder']);
        Route::post('/orders/{id}/accept', [FuelProviderOrderController::class, 'acceptOrder']);
        Route::post('/orders/{id}/location', [FuelProviderOrderController::class, 'shareLocation']);
        Route::patch('/orders/{id}/status', [FuelProviderOrderController::class, 'updateOrderStatus']);
        Route::post('/orders/{id}/cancel', [FuelProviderOrderController::class, 'cancelOrder']);
        Route::get('/my_orders', [FuelProviderOrderController::class, 'myOrders']);
    });
});

Route::middleware('auth:sanctum')->prefix('customer')->group(function () {

    Route::get('/fuel_orders', [FuelOrderController::class, 'index']);
    Route::get('/fuel_orders/{id}', [FuelOrderController::class, 'show']);
    Route::post('/fuel_orders/cancel/{id}', [FuelOrderController::class, 'cancel']);

    Route::post('/fuel_orders/emergency', [FuelOrderController::class, 'emergency']);

    Route::get('/fuel_orders/{id}/track', [FuelOrderController::class, 'trackProvider']);
});

// routes/api.php

Route::middleware(['auth:sanctum'])->prefix('shop')->group(function () {
    Route::get('/profile', [ShopController::class, 'profile']);
    Route::post('/profile', [ShopController::class, 'storeOrUpdateProfile']);
    Route::put('/profile', [ShopController::class, 'storeOrUpdateProfile']);

    Route::middleware('role:shop-owner')->group(function () {
        Route::get('/products', [ShopController::class, 'products']);
        Route::post('/products', [ShopController::class, 'storeProduct']);
        Route::put('/products/{id}', [ShopController::class, 'updateProduct']);
        Route::delete('/products/{id}', [ShopController::class, 'deleteProduct']);

        Route::get('/orders', [ShopController::class, 'orders']);
        Route::get('/orders/{id}', [ShopController::class, 'orderDetails']);
        Route::post('/orders/{id}/accept', [ShopController::class, 'acceptOrder']);
        Route::post('/orders/{id}/reject', [ShopController::class, 'rejectOrder']);
        Route::post('/orders/{id}/status', [ShopController::class, 'updateOrderStatus']);
        Route::post('/orders/{id}/location', [ShopController::class, 'shareDeliveryLocation']);
    });
});

// routes/api.php

Route::middleware('auth:sanctum')->prefix('customer')->group(function () {

    Route::get('/shops', [CustomerShopController::class, 'shops']);
    Route::get('/shops/{id}', [CustomerShopController::class, 'shopDetails']);
    Route::get('/shops/{id}/products', [CustomerShopController::class, 'shopProducts']);
    Route::get('/products', [CustomerShopController::class, 'products']);
    Route::get('/products/{id}', [CustomerShopController::class, 'productDetails']);

    Route::get('/cart', [CustomerOrderController::class, 'cart']);
    Route::post('/cart', [CustomerOrderController::class, 'addToCart']);
    Route::put('/cart/{id}', [CustomerOrderController::class, 'updateCart']);
    Route::delete('/cart/{id}', [CustomerOrderController::class, 'removeFromCart']);

    Route::post('/orders', [CustomerOrderController::class, 'createOrder']);
    Route::get('/orders', [CustomerOrderController::class, 'orders']);
    Route::get('/orders/{id}', [CustomerOrderController::class, 'orderDetails']);
    Route::post('/orders/{id}/cancel', [CustomerOrderController::class, 'cancelOrder']);

    Route::get('/orders/{id}/track', [CustomerShopController::class, 'trackDelivery']);

});
