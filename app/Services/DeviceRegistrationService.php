<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDeviceRegistration;
use Illuminate\Support\Facades\DB;

class DeviceRegistrationService
{
    public function registerDevice(User $user, array $data): UserDeviceRegistration
    {
        $fcmToken = $data['fcm_token'];
        $deviceId = $data['device_id'] ?? null;
        $platform = $data['platform'] ?? 'android';

        $attributes = [
            'user_id' => $user->id,
            'fcm_token' => $fcmToken,
            'platform' => $platform,
            'device_id' => $deviceId,
            'is_active' => true,
            'failed_count' => 0,
            'last_used_at' => now(),
        ];

        return DB::transaction(function () use ($user, $fcmToken, $deviceId, $attributes) {
            UserDeviceRegistration::where('fcm_token', $fcmToken)
                ->when(
                    $deviceId,
                    fn ($query) => $query->where(function ($q) use ($user, $deviceId) {
                        $q->where('user_id', '!=', $user->id)->orWhere('device_id', '!=', $deviceId);
                    }),
                    fn ($query) => $query->where('user_id', '!=', $user->id)
                )
                ->delete();

            $keys = $deviceId
                ? ['user_id' => $user->id, 'device_id' => $deviceId]
                : ['fcm_token' => $fcmToken];

            return UserDeviceRegistration::updateOrCreate($keys, $attributes);
        });
    }

    public function unregisterDevice(User $user, string $fcmToken): bool
    {
        return (bool) $user->deviceRegistrations()
            ->where('fcm_token', $fcmToken)
            ->delete();
    }
}
