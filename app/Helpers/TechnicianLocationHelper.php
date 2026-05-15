<?php

namespace App\Helpers;

trait TechnicianLocationHelper
{
    
    protected function formatTechnicianLocation($technician): ?array
    {
        if (!$technician || !$technician->latitude || !$technician->longitude) {
            return null;
        }
        
        return [
            'lat' => (float) $technician->latitude,
            'lng' => (float) $technician->longitude,
            'updated_at' => $technician->updated_at?->toDateTimeString(),
        ];
    }
}