<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SectorController;

// ════════════════════════════════════════════════════════════
//  مسارات إعدادات النظام القابلة للتعديل (قطاعات/رتب/توزيع).
//  تُحمَّل عبر ConfigRouteServiceProvider بدل routes/api.php تفادياً
//  لتصادم العمل القائم فيه — وتُدمَج معه لاحقاً عند استقراره.
// ════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->prefix('api')->group(function () {
    // ═══ إدارة القطاعات ═══ (الإضافة/التعديل/الحذف — الرمز عبر updatePrefix)
    Route::post('/sectors', [SectorController::class, 'store']);
    Route::put('/sectors/{id}', [SectorController::class, 'update'])->whereNumber('id');
    Route::delete('/sectors/{id}', [SectorController::class, 'destroy'])->whereNumber('id');
});
