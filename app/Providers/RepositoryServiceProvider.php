<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\Contracts\AuthRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Implementation\AuthRepository;
use App\Repositories\Implementation\UserRepository;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use App\Repositories\Implementation\VehicleRepository;
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
    }

    public function boot(): void
    {
        //
    }
}