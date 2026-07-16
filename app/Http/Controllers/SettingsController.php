<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\AuditLog;
use App\Security\Permissions;
use App\Services\ActiveDirectoryService;
use App\Services\CommunicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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

    // ════════════════ بوّابة الرسائل النصية ════════════════

    public function getSms(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $g = CommunicationService::gatewayConfig();

        // المفتاح لا يُعاد أبداً — فقط ما إذا كان مضبوطاً
        return response()->json(['sms' => [
            'enabled' => $g['enabled'],
            'url' => $g['url'],
            'senderId' => $g['sender'],
            'supportPhone' => $g['support'],
            'keySet' => $g['key'] !== '',
        ]]);
    }

    public function saveSms(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            // https فقط: يمنع إرسال المفتاح على اتصال واضح، ويقصّ مخططات SSRF (file/gopher/http داخلي)
            'url' => 'required_if:enabled,true|nullable|url|starts_with:https://|max:255',
            'apiKey' => 'nullable|string|max:500',
            'senderId' => 'required_if:enabled,true|nullable|string|max:20',
            'supportPhone' => 'nullable|string|max:20',
        ], [
            'url.required_if' => 'عنوان البوّابة مطلوب عند التفعيل',
            'url.starts_with' => 'عنوان البوّابة يجب أن يبدأ بـ https:// (لا يُرسَل المفتاح على اتصال غير مشفّر)',
            'senderId.required_if' => 'اسم المرسِل مطلوب عند التفعيل',
        ]);

        // مفتاح فارغ في الطلب = «أبقِ المفتاح الحالي» — الواجهة لا تملك المفتاح لتعيد إرساله
        $newKey = trim((string) ($validated['apiKey'] ?? ''));
        $hasExistingKey = CommunicationService::gatewayConfig()['key'] !== '';

        if ($validated['enabled'] && $newKey === '' && !$hasExistingKey) {
            return response()->json([
                'errors' => ['apiKey' => ['مفتاح البوّابة مطلوب عند التفعيل']],
            ], 422);
        }

        $map = [
            'sms.enabled' => $validated['enabled'] ? 'true' : 'false',
            'sms.url' => $validated['url'] ?? '',
            'sms.sender_id' => $validated['senderId'] ?? 'Kafaat',
            'sms.support_phone' => $validated['supportPhone'] ?? '',
        ];
        if ($newKey !== '') {
            $map['sms.key'] = Crypt::encryptString($newKey);
        }

        // كل المفاتيح + التدقيق ذرّياً — وإلا تركت الأعطال الجزئية بوّابةً نصف مُعدّة
        DB::transaction(function () use ($map, $validated, $request, $newKey) {
            foreach ($map as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'description' => 'إعداد بوّابة الرسائل', 'updated_at' => now()]
                );
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'UPDATE_SMS_SETTINGS',
                'entity_type' => 'settings',
                'entity_id' => '0',
                // لا يُسجَّل المفتاح — فقط ما إذا كان قد استُبدل
                'details' => ['enabled' => $validated['enabled'], 'keyReplaced' => $newKey !== ''],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'تم حفظ إعدادات بوّابة الرسائل']);
    }

    public function testSms(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $validated = $request->validate([
            'mobile' => ['required', 'string', 'regex:/^05[0-9]{8}$/'],
        ], [
            'mobile.regex' => 'رقم الجوال يجب أن يكون بصيغة 05XXXXXXXX',
        ]);

        // تدقيق كل محاولة اختبار (من، وإلى أي رقم) — الاختبار يرسل رسالة فعلية بتكلفة
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'TEST_SMS',
            'entity_type' => 'settings',
            'entity_id' => '0',
            'details' => ['mobile' => $validated['mobile']],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        $g = CommunicationService::gatewayConfig();
        if (!$g['enabled'] || $g['url'] === '' || $g['key'] === '') {
            return response()->json([
                'success' => false,
                'message' => 'البوّابة غير مفعّلة أو غير مكتملة — احفظ الإعدادات أولاً',
            ]);
        }

        $ok = app(CommunicationService::class)->sendSms(
            $validated['mobile'],
            'رسالة اختبار من منصة مركز تمكين الكفاءات',
            'notification',
            null,
            $request->user()->id
        );

        return response()->json($ok
            ? ['success' => true, 'message' => 'تم إرسال رسالة الاختبار — تحقّق من الجوال']
            : ['success' => false, 'message' => 'فشل الإرسال — راجع سجل الرسائل للتفاصيل']);
    }

    // ════════════════ خادم البريد (SMTP) ════════════════

    public function getSmtp(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $c = CommunicationService::smtpConfig();

        // كلمة المرور لا تُعاد أبداً — فقط ما إذا كانت مضبوطة
        return response()->json(['smtp' => [
            'enabled' => $c['enabled'],
            'host' => $c['host'],
            'port' => $c['port'],
            'encryption' => $c['encryption'],
            'username' => $c['username'],
            'fromAddress' => $c['fromAddress'],
            'fromName' => $c['fromName'],
            'passwordSet' => $c['password'] !== '',
        ]]);
    }

    public function saveSmtp(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'host' => 'required_if:enabled,true|nullable|string|max:255',
            'port' => 'required_if:enabled,true|nullable|integer|min:1|max:65535',
            'encryption' => 'required_if:enabled,true|nullable|in:tls,ssl,none',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:500',
            'fromAddress' => 'required_if:enabled,true|nullable|email|max:255',
            'fromName' => 'nullable|string|max:100',
        ], [
            'host.required_if' => 'عنوان الخادم مطلوب عند التفعيل',
            'port.required_if' => 'المنفذ مطلوب عند التفعيل',
            'fromAddress.required_if' => 'عنوان المرسِل مطلوب عند التفعيل',
            'fromAddress.email' => 'عنوان المرسِل يجب أن يكون بريداً صحيحاً',
        ]);

        // كلمة مرور فارغة = «أبقِ الحالية» — الواجهة لا تملكها لتعيد إرسالها
        $newPass = (string) ($validated['password'] ?? '');
        $hasExisting = CommunicationService::smtpConfig()['password'] !== '';

        $map = [
            'smtp.enabled' => $validated['enabled'] ? 'true' : 'false',
            'smtp.host' => $validated['host'] ?? '',
            'smtp.port' => (string) ($validated['port'] ?? 587),
            'smtp.encryption' => $validated['encryption'] ?? 'tls',
            'smtp.username' => $validated['username'] ?? '',
            'smtp.from_address' => $validated['fromAddress'] ?? '',
            'smtp.from_name' => $validated['fromName'] ?? '',
        ];
        if ($newPass !== '') {
            $map['smtp.password'] = Crypt::encryptString($newPass);
        }

        DB::transaction(function () use ($map, $validated, $request, $newPass) {
            foreach ($map as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'description' => 'إعداد خادم البريد', 'updated_at' => now()]
                );
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'UPDATE_SMTP_SETTINGS',
                'entity_type' => 'settings',
                'entity_id' => '0',
                // لا تُسجَّل كلمة المرور — فقط ما إذا استُبدلت
                'details' => ['enabled' => $validated['enabled'], 'passwordReplaced' => $newPass !== ''],
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        // خادم مُفعَّل بلا كلمة مرور قد يكون صحيحاً (مُرحِّل داخلي بلا مصادقة) — تنبيه لا رفض
        $warn = ($validated['enabled'] && $newPass === '' && !$hasExisting && ($validated['username'] ?? '') !== '')
            ? ' — تنبيه: اسم مستخدم بلا كلمة مرور'
            : '';

        return response()->json(['message' => 'تم حفظ إعدادات خادم البريد' . $warn]);
    }

    public function testSmtp(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية'], 403);
        }

        $validated = $request->validate(['email' => 'required|email|max:255']);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'TEST_SMTP',
            'entity_type' => 'settings',
            'entity_id' => '0',
            'details' => ['email' => $validated['email']],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        $c = CommunicationService::smtpConfig();
        if (!$c['enabled'] || $c['host'] === '') {
            return response()->json([
                'success' => false,
                'message' => 'خادم البريد غير مفعّل أو غير مكتمل — احفظ الإعدادات أولاً',
            ]);
        }

        $ok = app(CommunicationService::class)->sendEmail(
            $validated['email'],
            null,
            'رسالة اختبار — منصة مركز تمكين الكفاءات',
            "هذه رسالة اختبار من منصة مركز تمكين الكفاءات.\nوصولها يعني أن إعدادات خادم البريد صحيحة.",
            'notification',
            null,
            $request->user()->id
        );

        if ($ok) {
            return response()->json(['success' => true, 'message' => 'تم إرسال رسالة الاختبار — تحقّق من البريد']);
        }

        // سبب الفشل مكتوب في السجل — أعِده ليرى المشرف الخطأ الحقيقي بدل «فشل» مبهمة
        $err = \App\Models\EmailLog::latest('id')->first()?->error_message;
        return response()->json([
            'success' => false,
            'message' => 'فشل الإرسال' . ($err ? ': ' . mb_substr($err, 0, 180) : ' — راجع سجل البريد'),
        ]);
    }

    // ── التوزيع الأسبوعي: الحدّ لكل مقيّم في اليوم ──

    public function getDistribution(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        return response()->json(['distribution' => [
            'dailyCap' => (int) (Setting::find('distribution.daily_cap_per_evaluator')?->value ?? 5),
        ]]);
    }

    public function saveDistribution(Request $request)
    {
        if (!$request->user()->hasPermission(Permissions::SETTINGS_MANAGE)) {
            return response()->json(['error' => 'ليس لديك صلاحية إدارة الإعدادات'], 403);
        }

        $validated = $request->validate(
            ['dailyCap' => 'required|integer|min:1|max:50'],
            ['dailyCap.min' => 'الحدّ الأدنى مرشّح واحد', 'dailyCap.max' => 'الحدّ الأقصى 50 مرشّحاً']
        );

        Setting::updateOrCreate(
            ['key' => 'distribution.daily_cap_per_evaluator'],
            ['value' => (string) $validated['dailyCap'], 'description' => 'عدد المرشحين لكل مقيّم في اليوم']
        );

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'UPDATE_DISTRIBUTION_CAP',
            'entity_type' => 'settings',
            'entity_id' => '0',
            'details' => ['dailyCap' => $validated['dailyCap']],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'تم حفظ الحدّ', 'dailyCap' => $validated['dailyCap']]);
    }
}
