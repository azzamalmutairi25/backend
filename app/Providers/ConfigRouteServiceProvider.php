<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

// يُحمّل مسارات الإعدادات القابلة للتعديل من routes/config.php — ملفٌ منفصل
// كي لا نلمس routes/api.php (قيد التطوير). loadRoutesFrom آمنٌ مع route:cache
// (يتخطّى التحميل عند وجود ذاكرة مسارات مؤقتة).
class ConfigRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/config.php'));
    }
}
