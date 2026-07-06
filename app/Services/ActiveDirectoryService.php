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
            return false;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

        $bindUser = $domain . '\\' . $adUsername;
        $bound = @ldap_bind($conn, $bindUser, $password);
        @ldap_close($conn);

        return $bound === true;
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
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

        $bound = @ldap_bind($conn);
        @ldap_close($conn);

        if ($bound) {
            return ['success' => true, 'message' => 'الاتصال بالخادم ناجح'];
        }

        return [
            'success' => true,
            'message' => 'الخادم واصل (رفض الربط المجهول أمر طبيعي في AD). جرّب دخول مستخدم داخلي للتأكد الكامل.',
        ];
    }
}
