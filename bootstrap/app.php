<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // خلف الوسيط العكسي في الـ DMZ: بدون هذا يُفهرَس throttle على IP الوسيط
        // (فيقع كل الإنترنت في دلو واحد) ويُسجَّل ip_address الوسيط لا المشارك.
        //
        // القائمة تُقرأ داخل الوسيط من config('security.trusted_proxies') لا هنا:
        // هذا الملف يُنفَّذ قبل تحميل البيئة، ومع config:cache في الإنتاج لا يُقرأ
        // .env إطلاقاً — فكانت env() هنا تُرجِع فارغاً على الخادم دائماً، ويسقط
        // تقييد المعدّل وصحّة سجل التدقيق بصمت. راجع App\Http\Middleware\TrustProxies.
        $middleware->replace(
            \Illuminate\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\TrustProxies::class,
        );

        // فرض تغيير كلمة المرور خادمياً (كان في الواجهة فقط). يُلحق بمجموعة api
        // فيسري على كل مسارات /api؛ الوسيط نفسه يتخطّى غير المُصادَق والمسارات المسموحة.
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsurePasswordChanged::class);

        // تقييد معدّل عام على كل مسارات /api (المعرّف 'api' في AppServiceProvider).
        // يسدّ غياب الحدّ عن الـ85 مساراً المحمية؛ الحدود الأخصّ (login 10/د، البوّابة
        // 20/د) تبقى وتغلب لأنها أصرم.
        $middleware->throttleApi();

        // لا مسار باسم «login» في تطبيق واجهةٍ برمجية محضة، ووسيط المصادقة
        // يستدعي route('login') فوراً لبناء وجهة التحويل — فيرمي
        // RouteNotFoundException قبل أن يصل الاستثناء إلى المُصيِّر أصلاً.
        // النتيجة: كل طلب غير مُصادَق لا يحمل Accept: application/json يعود
        // بـ500 بدل 401 — أي أنّ ماسحاً أمنياً أو فاحص جاهزية يرى «عطل خادم»
        // على نظامٍ سليم يرفض الدخول رفضاً صحيحاً.
        // إرجاع null يُسقط التحويل، فيتولّى shouldRenderJsonWhen أدناه الردّ.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
