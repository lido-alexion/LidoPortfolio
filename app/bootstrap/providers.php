<?php

use App\Providers\AppServiceProvider;
use App\Providers\BrokerServiceProvider;
use App\Providers\EvaluationServiceProvider;

return [
    AppServiceProvider::class,
    EvaluationServiceProvider::class,
    BrokerServiceProvider::class,
];
