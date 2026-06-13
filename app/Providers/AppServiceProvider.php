<?php

namespace App\Providers;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Queue::looping(function (): void {
            try {
                Redis::connection()->setex('worker:heartbeat', 15, now()->toIso8601String());
            } catch (Throwable) {
                // fail silently
            }
        });
    }
}
