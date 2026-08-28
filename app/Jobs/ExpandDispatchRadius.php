<?php

namespace App\Jobs;

use App\Services\FuelOrderService;
use App\Services\SosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExpandDispatchRadius implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $uniqueFor = 100;

    public function __construct(
        public string $serviceType,
        public int $requestId,
        public int $expectedRadiusKm,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->serviceType}:{$this->requestId}";
    }

    public function handle(FuelOrderService $fuelOrderService, SosService $sosService): void
    {
        match ($this->serviceType) {
            'fuel' => $fuelOrderService->expandDispatchRadius($this->requestId, $this->expectedRadiusKm),
            'sos' => $sosService->expandDispatchRadius($this->requestId, $this->expectedRadiusKm),
            default => Log::warning('dispatch.unknown_service_type', ['type' => $this->serviceType]),
        };
    }
}
