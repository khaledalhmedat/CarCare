<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\AuthRepositoryInterface;
use App\Repositories\Contracts\CarwashRepositoryInterface;
use App\Repositories\Contracts\CarWasherRepositoryInterface;
use App\Repositories\Implementation\CarwashRepository;
use App\Repositories\Implementation\CarWasherRepository;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Implementation\AuthRepository;
use App\Repositories\Implementation\UserRepository;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use App\Repositories\Contracts\MaintenanceRequestRepositoryInterface;
use App\Repositories\Contracts\FuelOrderRepositoryInterface;
use App\Repositories\Implementation\FuelOrderRepository;
use App\Repositories\Implementation\MaintenanceRequestRepository;
use App\Repositories\Implementation\VehicleRepository;
use App\Services\MaintenanceRequestService;
use App\Services\VehicleService;


use App\Models\User;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, function ($app) {
            return new UserRepository($app->make(User::class));
        });

        $this->app->bind(AuthRepositoryInterface::class, function ($app) {
            return new AuthRepository(
                $app->make(UserRepositoryInterface::class)
            );
        });

         $this->app->bind(VehicleRepositoryInterface::class, VehicleRepository::class);
        
        $this->app->singleton(VehicleService::class, function ($app) {
            return new VehicleService(
                $app->make(VehicleRepositoryInterface::class)
            );
        });

         $this->app->bind(
            MaintenanceRequestRepositoryInterface::class,
            MaintenanceRequestRepository::class
        );
        
        $this->app->singleton(MaintenanceRequestService::class, function ($app) {
            return new MaintenanceRequestService(
                $app->make(MaintenanceRequestRepositoryInterface::class)
            );
        });

          $this->app->bind(
            CarwashRepositoryInterface::class,
            CarwashRepository::class
        );

        $this->app->bind(
            FuelOrderRepositoryInterface::class,
            FuelOrderRepository::class
        );

        $this->app->bind(
            CarWasherRepositoryInterface::class,
            CarWasherRepository::class
        );
    }

    public function boot(): void
    {
        //
    }
}