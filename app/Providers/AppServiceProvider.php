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
        // معرّف معدّل api — يُطبَّق على كل مسارات /api عبر throttleApi (bootstrap/app.php).
        // كانت الـ85 مساراً المحمية بلا أي حدّ (سطح استنزاف وتصدير جماعي). الحدّ سخيّ
        // (300/دقيقة) فلا يعيق لوحة تحمّل عدّة نقاط، ويُضبط عبر API_RATE_LIMIT.
        // المفتاح لكل مستخدم مُصادَق (لا لكل IP، فالموظفون قد يتشاركون IP خروج)،
        // ويرجع لـIP للطلبات غير المُصادَقة. مُعطَّل في الاختبار كي لا يتراكم عبر السويت.
        RateLimiter::for('api', function (Request $request) {
            if ($this->app->environment('testing')) {
                return Limit::none();
            }

            $user = auth('sanctum')->user();
            $key = $user?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute((int) env('API_RATE_LIMIT', 300))->by((string) $key);
        });
    }
}
