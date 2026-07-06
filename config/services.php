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

];
