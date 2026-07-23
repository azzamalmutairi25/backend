<?php

// ════════════════════════════════════════════════════════════
//  إعدادات الخدمات الخارجية
// ════════════════════════════════════════════════════════════

return [

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    // ── بوابة الرسائل النصية ──
    'sms' => [
        'url' => env('SMS_API_URL'),
        'key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID', 'Kafaat'),
        'support_phone' => env('SMS_SUPPORT_PHONE', ''),
    ],

    // بوّابة التحقق من الهوية (يقين/أبشر أو أي مزوّد) — الرجوع للبيئة إن لم تُضبط في الإعدادات
    'idverify' => [
        'url' => env('IDVERIFY_API_URL'),
        'key' => env('IDVERIFY_API_KEY'),
        'app_id' => env('IDVERIFY_APP_ID', ''),
        'provider' => env('IDVERIFY_PROVIDER', 'generic'), // generic | yakeen
    ],

];
