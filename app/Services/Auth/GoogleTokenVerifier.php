<?php

namespace App\Services\Auth;

use App\Exceptions\GoogleAuthException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleTokenVerifier
{
    protected const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
    protected const VALID_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * Verify a Google ID token server-side and return the normalized claims.
     *
     * Verification is delegated to Google's tokeninfo endpoint (which checks the
     * signature and expiry), then we additionally enforce audience + issuer + expiry
     * locally. Never trusts client-supplied identity beyond what Google returns.
     *
     * @return array{sub:string,email:string,name:string,avatar:?string,email_verified:bool}
     * @throws GoogleAuthException
     */
    public function verify(string $idToken): array
    {
        $allowedClientIds = $this->allowedClientIds();

        if (empty($allowedClientIds)) {
            // env not provisioned — never fake verification
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

        // audience must match one of our configured client IDs
        if (!in_array($claims['aud'] ?? null, $allowedClientIds, true)) {
            throw GoogleAuthException::invalidToken();
        }

        // issuer must be Google
        if (!in_array($claims['iss'] ?? '', self::VALID_ISSUERS, true)) {
            throw GoogleAuthException::invalidToken();
        }

        // expiry (defense-in-depth; tokeninfo already rejects expired tokens)
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
            // tokeninfo returns email_verified as the string "true"/"false"
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @return array<int,string>  allowed audience client IDs (supports comma-separated multi-platform)
     */
    protected function allowedClientIds(): array
    {
        $configured = (string) config('services.google.client_id');

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
