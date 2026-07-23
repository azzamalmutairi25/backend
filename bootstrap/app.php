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
        // (فيقع كل الإنترنت في دلو واحد) ويُسجَّل ip_address الوسيط لا المرشّح.
        // نثق بعناوين الوسطاء صراحةً من TRUSTED_PROXIES (مثال: 10.0.0.0/29)،
        // لا بـ '*' لأنها تسمح بانتحال X-Forwarded-For. فارغ = لا نثق بأحد (سلوك التطوير).
        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));

        $middleware->trustProxies(
            at: $proxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // فرض تغيير كلمة المرور خادمياً (كان في الواجهة فقط). يُلحق بمجموعة api
        // فيسري على كل مسارات /api؛ الوسيط نفسه يتخطّى غير المُصادَق والمسارات المسموحة.
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsurePasswordChanged::class);

        // تقييد معدّل عام على كل مسارات /api (المعرّف 'api' في AppServiceProvider).
        // يسدّ غياب الحدّ عن الـ85 مساراً المحمية؛ الحدود الأخصّ (login 10/د، البوّابة
        // 20/د) تبقى وتغلب لأنها أصرم.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
