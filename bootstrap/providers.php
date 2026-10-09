<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    BroadcastServiceProvider::class,
    RateLimitServiceProvider::class,
];
