<?php

namespace App\Services\AccessRequest;

class TestingHumanVerificationService implements HumanVerificationService
{
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        return is_string($token) && trim($token) !== '';
    }
}
