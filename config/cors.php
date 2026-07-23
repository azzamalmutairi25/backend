<?php

// ════════════════════════════════════════════════════════════
//  إعداد CORS صريح — بدون هذا الملف يسري افتراضي الإطار
//  allowed_origins => ['*'] وهو غير لائق بمنصّة بهذا التصنيف.
//
//  المصادر تُقرأ من CORS_ALLOWED_ORIGINS (مفصولة بفواصل). في الإنتاج
//  اضبطها على نطاق الواجهة الحقيقي حصراً. القيمة الافتراضية هنا نطاقات
//  التطوير المحليّة فقط — صريحة لا '*'.
//
//  supports_credentials=false: المصادقة برموز Bearer في ترويسة Authorization
//  لا بكوكيز، فلا حاجة لإرسال بيانات الاعتماد عبر المتصفّح.
// ════════════════════════════════════════════════════════════

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:8000'))
)));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
