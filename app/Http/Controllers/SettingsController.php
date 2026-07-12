<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Services\ActiveDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    private array $ldapKeys = [
        'ldap.enabled', 'ldap.host', 'ldap.port',
        'ldap.domain', 'ldap.base_dn', 'ldap.use_ssl',
    ];

    public function getLdap(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $s = Setting::whereIn('key', $this->ldapKeys)->pluck('value', 'key');

        return response()->json(['ldap' => [
            'enabled' => ($s['ldap.enabled'] ?? 'false') === 'true',
            'host' => $s['ldap.host'] ?? '',
            'port' => $s['ldap.port'] ?? '389',
            'domain' => $s['ldap.domain'] ?? '',
            'baseDn' => $s['ldap.base_dn'] ?? '',
            'useSsl' => ($s['ldap.use_ssl'] ?? 'false') === 'true',
        ]]);
    }

    public function saveLdap(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'host' => 'required_if:enabled,true|nullable|string|max:255',
            'port' => 'required_if:enabled,true|nullable|integer|min:1|max:65535',
            'domain' => 'required_if:enabled,true|nullable|string|max:100',
            'baseDn' => 'nullable|string|max:255',
            'useSsl' => 'boolean',
        ], [
            'host.required_if' => 'عنوان الخادم مطلوب عند تفعيل LDAP',
            'port.required_if' => 'المنفذ مطلوب عند تفعيل LDAP',
            'domain.required_if' => 'اسم الدومين مطلوب عند تفعيل LDAP',
        ]);

        $map = [
            'ldap.enabled' => $validated['enabled'] ? 'true' : 'false',
            'ldap.host' => $validated['host'] ?? '',
            'ldap.port' => (string) ($validated['port'] ?? '389'),
            'ldap.domain' => $validated['domain'] ?? '',
            'ldap.base_dn' => $validated['baseDn'] ?? '',
            'ldap.use_ssl' => ($validated['useSsl'] ?? false) ? 'true' : 'false',
        ];

        // كل المفاتيح + سجل التدقيق ذرّياً — وإلا تركت أعطالٌ جزئية LDAP نصف مُعدّ (host جديد، port قديم)
        DB::transaction(function () use ($map, $validated, $request) {
            foreach ($map as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'description' => 'إعداد LDAP', 'updated_at' => now()]
                );
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'UPDATE_LDAP_SETTINGS',
                'entity_type' => 'settings',
                'entity_id' => '0',
                'details' => ['enabled' => $validated['enabled']],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'تم حفظ إعدادات LDAP']);
    }

    public function testLdap(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        // منفذ محصور بمنافذ LDAP القياسية — يمنع استخدام الاختبار كماسح منافذ (SSRF)
        $validated = $request->validate([
            'host' => 'required|string|max:255',
            'port' => 'required|integer|in:389,636,3268,3269',
            'useSsl' => 'boolean',
        ], [
            'port.in' => 'المنفذ يجب أن يكون أحد منافذ LDAP القياسية (389/636/3268/3269)',
        ]);

        // تدقيق كل محاولة اختبار (من، وإلى أي خادم)
        \App\Models\AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'TEST_LDAP',
            'details' => ['host' => $validated['host'], 'port' => (int) $validated['port']],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        $result = ActiveDirectoryService::testConnection(
            $validated['host'],
            (int) $validated['port'],
            $validated['useSsl'] ?? false
        );

        return response()->json($result);
    }
}
