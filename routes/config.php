<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SectorController;
use App\Http\Controllers\RankController;

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

    // ═══ إدارة الرتب/المراتب ═══ (تقود تصنيف الفئة القيادية)
    Route::get('/ranks', [RankController::class, 'index']);
    Route::post('/ranks', [RankController::class, 'store']);
    Route::put('/ranks/{id}', [RankController::class, 'update'])->whereNumber('id');
    Route::delete('/ranks/{id}', [RankController::class, 'destroy'])->whereNumber('id');
});
