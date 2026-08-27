<?php

namespace App\Providers;

use App\Services\Broker\BrokerGateway;
use App\Services\Broker\FakeBrokerGateway;
use App\Services\Broker\KiteBrokerGateway;
use Illuminate\Support\ServiceProvider;

class BrokerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeBrokerGateway::class);

        if ($this->app->environment('testing')) {
            $this->app->singleton(BrokerGateway::class, fn ($app) => $app->make(FakeBrokerGateway::class));
        } else {
            $this->app->singleton(BrokerGateway::class, KiteBrokerGateway::class);
        }
    }
}
