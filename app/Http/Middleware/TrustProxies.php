<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

// ════════════════════════════════════════════════════════════
//  الوسطاء الموثوقون — تُقرأ قائمتهم من الإعدادات لا من env() المباشرة.
//
//  الفرق ليس تجميلياً: bootstrap/app.php يُنفَّذ قبل تحميل البيئة، ومع
//  `config:cache` في الإنتاج لا يُقرأ ملف .env أصلاً — فكانت القائمة تخرج
//  فارغةً دائماً على الخادم، ويسقط معها تقييد المعدّل وصحّة سجل التدقيق.
//
//  الوسيط يُحلّ من الحاوية عند الطلب، أي بعد تحميل الإعدادات — فقراءة
//  config() هنا تعمل مع الذاكرة المؤقّتة وبدونها سواء.
// ════════════════════════════════════════════════════════════
class TrustProxies extends Middleware
{
    // ترويسات الوسيط التي نقبلها. لا نقبل HEADER_FORWARDED (RFC 7239)
    // ما لم يُرسلها وسيطنا فعلاً — قبول ما لا يُنتَج توسيعٌ بلا داعٍ.
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    protected function proxies()
    {
        $proxies = config('security.trusted_proxies', []);

        // قائمة فارغة ⇒ null صراحةً: لا نثق بأحد. الإرجاع بمصفوفة فارغة
        // يمرّ عبر ?: في الأصل فيُقرأ كأنه «غير مضبوط»، وهو الالتباس نفسه.
        return $proxies ?: null;
    }
}
