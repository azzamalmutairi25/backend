<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ════════════════════════════════════════════════════════════
//  فرض تغيير كلمة المرور خادمياً.
//  كان الإلزام في الواجهة فقط، فمستخدمٌ أُعيد تعيين كلمة مروره
//  (must_change_password=true) يستطيع تخطّي الشاشة واستدعاء الـAPI مباشرة.
//  هذا الوسيط يمنع كل الإجراءات حتى تُغيَّر كلمة المرور، عدا: تغييرها،
//  والخروج، وقراءة الملف الشخصي (/me) — وإلا حُبس المستخدم بلا مخرج.
//
//  يُحلّ المستخدم عبر حارس sanctum مباشرة لا عبر $request->user()، فيعمل
//  بصرف النظر عن ترتيبه مع auth:sanctum (المجموعة قبل وسيط المسار).
//  المستخدم غير المُصادَق (الدخول/البوّابة العامة) يمرّ بلا مساس.
// ════════════════════════════════════════════════════════════

class EnsurePasswordChanged
{
    // المسارات المسموح بها رغم إلزام تغيير كلمة المرور
    private const ALLOWED = [
        'api/change-password',
        'api/logout',
        'api/me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('sanctum')->user();

        if ($user && $user->must_change_password && ! $request->is(self::ALLOWED)) {
            return response()->json([
                'error' => 'يجب تغيير كلمة المرور قبل المتابعة',
                'mustChangePassword' => true,
            ], 403);
        }

        return $next($request);
    }
}
