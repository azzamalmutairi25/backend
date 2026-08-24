<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

// ════════════════════════════════════════════════════════════
//  بوّابة لوحة Horizon
//
//  اللوحة تكشف حمولات المهامّ: أسماء مشاركين، نصوص رسائل. فهي بابٌ
//  حسّاس يُؤمَّن بالدور، لا بالمسار وحده. مدير النظام (ADMIN) وحده.
//
//  الوصول في الإنتاج: النظام واجهةُ برمجةٍ بمصادقة رمزٍ لا جلسة ويب،
//  فلا جلسة للوحة إلا عبر تسجيل دخول ويب لمدير النظام. الأسلم أن تُبلَغ
//  اللوحة عبر نفقٍ SSH إلى الخادم الداخلي. وإذ لا مستخدم في الجلسة تردّ
//  البوّابة false — المنع هو وضع الفشل الآمن، لا الفتح.
// ════════════════════════════════════════════════════════════

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * بوّابة الوصول: مدير النظام وحده.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null
                && $user->role !== null
                && $user->hasRole('ADMIN');
        });
    }
}
