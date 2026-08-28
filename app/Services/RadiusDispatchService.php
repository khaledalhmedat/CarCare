<?php

namespace App\Services;

use App\Jobs\ExpandDispatchRadius;
use App\Jobs\MaxRadiusRecheckJob;
use App\Models\DispatchNotificationRecipient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RadiusDispatchService
{
    public const INITIAL_RADIUS_KM = 10;
    public const RADIUS_STEP_KM = 10;
    public const EXPANSION_INTERVAL_SECONDS = 90;
    public const MAX_RADIUS_RECHECK_INTERVAL_SECONDS = 300;

    public function maxRadiusKm(): int
    {
        return (int) config('dispatch.max_search_radius_km', 70);
    }

    public function advance(
        Model $request,
        string $serviceType,
        string $recipientType,
        int $startRadius,
        callable $findCandidates,
        callable $notifyBatch
    ): bool {
        $max = $this->maxRadiusKm();

        for ($radius = $startRadius; $radius <= $max; $radius += self::RADIUS_STEP_KM) {
            $candidates = $findCandidates($radius);
            $new = $this->filterUnnotified($request, $serviceType, $recipientType, $candidates);

            if ($new->isEmpty()) {
                continue;
            }

            $request->forceFill([
                'current_radius_km' => $radius,
                'radius_stage_started_at' => now(),
            ])->save();

            DB::afterCommit(function () use ($notifyBatch, $new, $serviceType, $request, $radius) {
                $notifyBatch($new);
                ExpandDispatchRadius::dispatch($serviceType, $request->getKey(), $radius)
                    ->delay(now()->addSeconds(self::EXPANSION_INTERVAL_SECONDS));
            });

            return true;
        }

        $request->forceFill([
            'current_radius_km' => $max,
            'radius_stage_started_at' => now(),
        ])->save();

        DB::afterCommit(fn () => MaxRadiusRecheckJob::dispatch($serviceType, $request->getKey())
            ->delay(now()->addSeconds(self::MAX_RADIUS_RECHECK_INTERVAL_SECONDS)));

        return false;
    }

    private function filterUnnotified(
        Model $request,
        string $serviceType,
        string $recipientType,
        Collection $candidates
    ): Collection {
        $alreadyIds = DispatchNotificationRecipient::where('service_type', $serviceType)
            ->where('request_id', $request->getKey())
            ->where('recipient_type', $recipientType)
            ->whereIn('recipient_id', $candidates->pluck('id'))
            ->pluck('recipient_id');

        return $candidates->whereNotIn('id', $alreadyIds)->values();
    }
}
