<?php

namespace App\Services;

use App\Models\Setting;

class ActiveDirectoryService
{
    private static function get(string $key, string $default = ''): string
    {
        return Setting::where('key', $key)->value('value') ?? $default;
    }

    public static function isEnabled(): bool
    {
        return self::get('ldap.enabled', 'false') === 'true';
    }

    public static function authenticate(string $adUsername, string $password): ?bool
    {
        if (!self::isEnabled()) {
            return null;
        }

        if (!function_exists('ldap_connect')) {
            return null;
        }

        $host = self::get('ldap.host');
        $port = (int) self::get('ldap.port', '389');
        $domain = self::get('ldap.domain');
        $useSsl = self::get('ldap.use_ssl', 'false') === 'true';

        if ($host === '' || $domain === '') {
            return null;
        }

        $protocol = $useSsl ? 'ldaps://' : 'ldap://';
        $conn = @ldap_connect($protocol . $host, $port);
        if (!$conn) {
            return null; // فشل إعداد اتصال (بنية تحتية) لا رفض اعتماد — لا تُعاقب المستخدم
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        // NETWORK_TIMEOUT يغطّي إنشاء الاتصال فقط؛ TIMEOUT يحدّ انتظار ردّ العملية (bind).
        // بدون الثاني: خادم AD يقبل TCP ثم لا يردّ ⇒ يتجمّد عامل PHP حتى max_execution_time
        // لا 5ث — فيستنفد عمّال FPM عند دخول الموظفين صباحاً ويسقط النظام بعطل في AD.
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        ldap_set_option($conn, LDAP_OPT_TIMEOUT, 5);

        $bindUser = $domain . '\\' . $adUsername;
        $bound = @ldap_bind($conn, $bindUser, $password);
        $errno = $bound ? 0 : @ldap_errno($conn);
        @ldap_close($conn);

        if ($bound) {
            return true;
        }
        // 48/49/50 = بيانات اعتماد خاطئة/غير كافية → فشل مصادقة حقيقي (يُعاقَب بعدّاد القفل)
        if (in_array($errno, [48, 49, 50], true)) {
            return false;
        }
        // خلاف ذلك (خادم متوقّف/شبكة/مهلة) → «غير متاح»: لا تُعاقب مستخدماً أدخل كلمة مرور صحيحة
        return null;
    }

    public static function testConnection(string $host, int $port, bool $useSsl = false): array
    {
        if (!function_exists('ldap_connect')) {
            return [
                'success' => false,
                'message' => 'إضافة LDAP غير مثبّتة في PHP (php-ldap)',
            ];
        }

        $protocol = $useSsl ? 'ldaps://' : 'ldap://';
        $conn = @ldap_connect($protocol . $host, $port);
        if (!$conn) {
            return ['success' => false, 'message' => 'تعذّر إنشاء اتصال بالخادم'];
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        // NETWORK_TIMEOUT يغطّي إنشاء الاتصال فقط؛ TIMEOUT يحدّ انتظار ردّ العملية (bind).
        // بدون الثاني: خادم AD يقبل TCP ثم لا يردّ ⇒ يتجمّد عامل PHP حتى max_execution_time
        // لا 5ث — فيستنفد عمّال FPM عند دخول الموظفين صباحاً ويسقط النظام بعطل في AD.
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        ldap_set_option($conn, LDAP_OPT_TIMEOUT, 5);

        $bound = @ldap_bind($conn);
        $errno = $bound ? 0 : @ldap_errno($conn);
        @ldap_close($conn);

        if ($bound) {
            return ['success' => true, 'message' => 'الاتصال بالخادم ناجح'];
        }

        // 48/49 = الخادم واصل لكنه رفض الربط المجهول (طبيعي في AD)
        if (in_array($errno, [48, 49], true)) {
            return ['success' => true, 'message' => 'الخادم واصل (رفض الربط المجهول طبيعي في AD). جرّب دخول مستخدم داخلي للتأكد الكامل.'];
        }

        // خلاف ذلك: الخادم غير قابل للوصول (متوقّف/عنوان أو منفذ خاطئ)
        return ['success' => false, 'message' => "تعذّر الوصول لخادم AD — تأكد من العنوان والمنفذ (رمز {$errno})"];
    }
}
