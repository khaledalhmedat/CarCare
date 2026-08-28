<?php

namespace App\Console\Commands;

use App\Models\FuelOrder;
use App\Models\SosRequest;
use App\Services\FuelOrderService;
use App\Services\SosService;
use Illuminate\Console\Command;

class BootstrapDispatchRecovery extends Command
{
    protected $signature = 'dispatch:bootstrap-recovery';

    protected $description = 'One-time: start the max-radius recheck chain for pending/open requests already sitting at the search ceiling with no recovery chain scheduled.';

    public function handle(FuelOrderService $fuel, SosService $sos): int
    {
        $max = config('dispatch.max_search_radius_km', 70);

        $fuelIds = FuelOrder::where('status', 'pending')->where('current_radius_km', $max)->pluck('id');
        foreach ($fuelIds as $id) {
            $fuel->recheckMaxRadius($id);
        }

        $sosIds = SosRequest::where('status', 'open')->where('current_radius_km', $max)->pluck('id');
        foreach ($sosIds as $id) {
            $sos->recheckMaxRadius($id);
        }

        $this->info("Bootstrapped {$fuelIds->count()} fuel order(s) and {$sosIds->count()} SOS request(s).");

        return self::SUCCESS;
    }
}
