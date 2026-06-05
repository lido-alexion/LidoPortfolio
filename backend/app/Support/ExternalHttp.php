<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ExternalHttp
{
    public static function client(): PendingRequest
    {
        $options = ['timeout' => 25];

        $caFile = config('portfolio.ca_bundle');
        if ($caFile && is_readable($caFile)) {
            $options['verify'] = $caFile;
        } elseif (config('portfolio.ssl_verify') === false) {
            $options['verify'] = false;
        }

        return Http::withOptions($options);
    }
}
