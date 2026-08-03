<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // General API: 60 req/min per user or IP
        RateLimiter::for('api', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();

            return Limit::perMinute(60)->by('api:'.$key);
        });

        // Login / auth endpoints: stricter
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email', '');

            return [
                Limit::perMinute(5)->by('login:ip:'.$request->ip()),
                Limit::perMinute(5)->by('login:email:'.strtolower($email)),
            ];
        });

        // RAG chat (heavier): 20 req/min per user
        RateLimiter::for('rag', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();

            return Limit::perMinute(20)->by('rag:'.$key);
        });
    }
}
