<?php

// للتذكير: هذا الملف يتحقق من رمز جوجل (ID token) عبر خادم جوجل ويتأكد من الجمهور والمُصدِر والصلاحية.

namespace App\Services\Auth;

use App\Exceptions\GoogleAuthException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleTokenVerifier
{
    protected const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
    protected const VALID_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function verify(string $idToken): array
    {
        $allowedClientIds = $this->allowedClientIds();

        if (empty($allowedClientIds)) {
            throw GoogleAuthException::notConfigured();
        }

        try {
            $response = Http::timeout(10)->get(self::TOKENINFO_URL, ['id_token' => $idToken]);
        } catch (\Throwable $e) {
            Log::warning('Google tokeninfo request failed', ['error' => $e->getMessage()]);
            throw GoogleAuthException::invalidToken();
        }

        if (!$response->ok()) {
            throw GoogleAuthException::invalidToken();
        }

        $claims = $response->json();

        if (!is_array($claims)) {
            throw GoogleAuthException::invalidToken();
        }

        if (!in_array($claims['aud'] ?? null, $allowedClientIds, true)) {
            throw GoogleAuthException::invalidToken();
        }

        if (!in_array($claims['iss'] ?? '', self::VALID_ISSUERS, true)) {
            throw GoogleAuthException::invalidToken();
        }

        if (!isset($claims['exp']) || (int) $claims['exp'] < time()) {
            throw GoogleAuthException::invalidToken();
        }

        if (empty($claims['sub']) || empty($claims['email'])) {
            throw GoogleAuthException::invalidToken();
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => strtolower((string) $claims['email']),
            'name' => $claims['name'] ?? ((string) $claims['email']),
            'avatar' => $claims['picture'] ?? null,
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    protected function allowedClientIds(): array
    {
        $configured = (string) config('services.google.client_id');

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
