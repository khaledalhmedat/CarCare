<?php

namespace App\Helpers;

trait HaversineTrait
{
    
    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lngDelta / 2) * sin($lngDelta / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
    
    
    protected function getNearbyTechnicians(float $lat, float $lng, int $radiusInKm = 30)
    {
        return \App\Models\Technician::where('is_available', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->filter(function ($technician) use ($lat, $lng, $radiusInKm) {
                $distance = $this->calculateDistance(
                    $lat, $lng,
                    $technician->latitude, $technician->longitude
                );
                $technician->distance = round($distance, 2);
                return $distance <= $radiusInKm;
            })
            ->sortBy('distance');
    }
}