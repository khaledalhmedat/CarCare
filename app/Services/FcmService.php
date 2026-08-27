<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_CACHE_KEY = 'fcm.oauth_access_token';
    private const TOKEN_CACHE_TTL = 3000;

    public function __construct(private Client $http)
    {
    }

    public function sendToToken(string $fcmToken, string $title, string $body, array $data = []): array
    {
        $config = $this->resolveConfig();

        if ($config === null) {
            return ['success' => false, 'error_code' => 'CONFIG_MISSING'];
        }

        try {
            $accessToken = $this->getAccessToken($config['credentials_path']);
        } catch (\Throwable $e) {
            Log::warning('fcm.auth_failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error_code' => 'AUTH_FAILED'];
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $this->normalizeData($data),
                'android' => [
                    'notification' => $this->resolveAndroidNotificationChannel($data['type'] ?? null),
                ],
            ],
        ];

        try {
            $this->http->post(
                "https://fcm.googleapis.com/v1/projects/{$config['project_id']}/messages:send",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$accessToken}",
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            return ['success' => true, 'error_code' => null];
        } catch (RequestException $e) {
            $errorCode = $this->extractErrorCode($e);

            Log::warning('fcm.send_failed', [
                'error_code' => $errorCode,
                'status' => $e->getResponse()?->getStatusCode(),
            ]);

            return ['success' => false, 'error_code' => $errorCode];
        }
    }

    protected function resolveConfig(): ?array
    {
        $projectId = config('services.firebase.project_id');
        $credentialsPath = config('services.firebase.credentials_path');

        if (!$projectId || !$credentialsPath || !is_file($credentialsPath)) {
            Log::warning('fcm.config_missing');

            return null;
        }

        return [
            'project_id' => $projectId,
            'credentials_path' => $credentialsPath,
        ];
    }

    protected function getAccessToken(string $credentialsPath): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_CACHE_TTL, function () use ($credentialsPath) {
            $credentials = new ServiceAccountCredentials(self::SCOPE, $credentialsPath);
            $token = $credentials->fetchAuthToken();

            return $token['access_token'];
        });
    }

    /**
     * Maps a notification `type` to the matching Flutter-side channel/sound/icon,
     * so the custom sound also plays for background/terminated notifications
     * (foreground notifications are built entirely client-side and unaffected).
     */
    private function resolveAndroidNotificationChannel(?string $type): array
    {
        $type ??= '';

        if ($type === 'new_sos_request' || str_starts_with($type, 'sos_')) {
            return ['channel_id' => 'car_care_sos', 'sound' => 'carcare_sos', 'icon' => 'ic_notification_sos'];
        }

        if (str_starts_with($type, 'carwash_')) {
            return ['channel_id' => 'car_care_wash', 'sound' => 'carcare_wash', 'icon' => 'ic_notification_wash'];
        }

        if ($type === 'new_emergency_fuel_order' || str_starts_with($type, 'fuel_')) {
            return ['channel_id' => 'car_care_fuel', 'sound' => 'carcare_fuel', 'icon' => 'ic_notification_fuel'];
        }

        if (str_starts_with($type, 'maintenance_')) {
            return ['channel_id' => 'car_care_maintenance', 'sound' => 'carcare_maintenance', 'icon' => 'ic_notification_maintenance'];
        }

        if (str_starts_with($type, 'spare_parts_')) {
            return ['channel_id' => 'car_care_spare_parts', 'sound' => 'carcare_spare_parts', 'icon' => 'ic_notification_spare_parts'];
        }

        return ['channel_id' => 'car_care_general', 'sound' => 'carcare_general', 'icon' => 'ic_notification_general'];
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $normalized[$key] = '';
            } elseif (is_scalar($value)) {
                $normalized[$key] = (string) $value;
            } else {
                $normalized[$key] = json_encode($value);
            }
        }

        return $normalized;
    }

    private function extractErrorCode(RequestException $e): string
    {
        $response = $e->getResponse();

        if (!$response) {
            return 'UNAVAILABLE';
        }

        $body = json_decode((string) $response->getBody(), true);

        foreach (($body['error']['details'] ?? []) as $detail) {
            if (isset($detail['errorCode'])) {
                return $detail['errorCode'];
            }
        }

        return $body['error']['status'] ?? 'UNKNOWN';
    }
}
