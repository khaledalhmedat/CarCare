<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Store an in-app notification for a user and attempt real-time delivery.
     *
     * The database write is the source of truth; the Reverb broadcast is best-effort.
     * Neither a broadcasting failure nor a storage failure is ever re-thrown to the
     * caller, so a business operation is never broken by notification delivery.
     */
    public function notifyUser(User $user, string $type, string $title, string $body, array $data = []): ?DatabaseNotification
    {
        $notification = null;

        try {
            $notification = $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $type,
                'data' => [
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('notification.store_failed', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // real-time delivery is best-effort — if Reverb is down/unconfigured, swallow + log
        try {
            broadcast(new NotificationCreated($user->id, [
                'id' => $notification->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'read_at' => null,
                'created_at' => optional($notification->created_at)->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('notification.broadcast_failed', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }

        return $notification;
    }
}
