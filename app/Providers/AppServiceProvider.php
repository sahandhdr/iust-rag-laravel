<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // API عمومی: ۶۰ درخواست در دقیقه به ازای کاربر یا IP
        RateLimiter::for('api', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();

            return Limit::perMinute(60)->by('api:'.$key);
        });

        // Auth: سخت‌گیرانه
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email', '');

            return [
                Limit::perMinute(5)->by('login:ip:'.$request->ip()),
                Limit::perMinute(5)->by('login:email:'.strtolower($email)),
            ];
        });

        // RAG (LLM سنگین)
        RateLimiter::for('rag', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();

            return Limit::perMinute(20)->by('rag:'.$key);
        });

        // آپلود / publish / reembed / wipe
        RateLimiter::for('heavy', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();

            return Limit::perMinute(10)->by('heavy:'.$key);
        });
    }
}
