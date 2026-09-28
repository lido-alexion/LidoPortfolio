<?php

namespace App\Services\AccessRequest;

use Illuminate\Support\Facades\Http;

class TurnstileHumanVerificationService implements HumanVerificationService
{
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        $secret = (string) config('access_requests.captcha.turnstile.secret_key');
        if ($secret === '' || ! is_string($token) || trim($token) === '') {
            return false;
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        if (! $response->ok()) {
            return false;
        }

        return (bool) ($response->json('success') ?? false);
    }
}
