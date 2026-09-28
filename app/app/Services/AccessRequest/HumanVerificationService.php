<?php

namespace App\Services\AccessRequest;

interface HumanVerificationService
{
    public function verify(?string $token, ?string $remoteIp = null): bool;
}
