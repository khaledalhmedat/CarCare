<?php

namespace App\Services;

use App\Models\SosRequest;
use App\Models\TrackingPoint;
use App\Events\TechnicianLocationUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrackingService
{
    public function updateLocation(SosRequest $sosRequest, int $technicianId, array $data): TrackingPoint
    {
        $point = DB::transaction(function () use ($sosRequest, $technicianId, $data) {
            return TrackingPoint::create([
                'sos_request_id' => $sosRequest->id,
                'technician_id' => $technicianId,
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'heading' => $data['heading'] ?? null,
                'speed' => $data['speed'] ?? null,
            ]);
        });

        try {
            event(new TechnicianLocationUpdated(
                $sosRequest->id,
                $technicianId,
                $data['lat'],
                $data['lng'],
                $data['heading'] ?? null,
                $data['speed'] ?? null
            ));
        } catch (\Throwable $e) {
            Log::warning('tracking.location.broadcast_failed', [
                'sos_id' => $sosRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $point;
    }

    public function getTrackingPoints(int $sosRequestId): array
    {
        $points = TrackingPoint::where('sos_request_id', $sosRequestId)
            ->orderBy('created_at', 'asc')
            ->get(['lat', 'lng', 'created_at']);

        return [
            'points' => $points,
            'total_points' => $points->count(),
            'started_at' => $points->first()?->created_at,
            'last_update' => $points->last()?->created_at,
        ];
    }

    public function getLastLocation(int $sosRequestId): ?TrackingPoint
    {
        return TrackingPoint::where('sos_request_id', $sosRequestId)
            ->latest()
            ->first();
    }
}