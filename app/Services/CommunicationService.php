<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\SmsLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

// ════════════════════════════════════════════════════════════
//  خدمات الاتصال: البريد الإلكتروني والرسائل النصية
// ════════════════════════════════════════════════════════════

class CommunicationService
{
    public const GATEWAY_KEYS = [
        'sms.enabled', 'sms.url', 'sms.key', 'sms.sender_id', 'sms.support_phone',
    ];

    // ── إعداد بوّابة الرسائل: جدول settings أولاً، ثم متغيّرات البيئة ──
    // الرجوع للبيئة يُبقي التنصيبات القائمة (المضبوطة عبر .env) شغّالة بلا تدخّل.
    public static function gatewayConfig(): array
    {
        $s = Setting::whereIn('key', self::GATEWAY_KEYS)->pluck('value', 'key');

        // المفتاح مُخزَّن مشفّراً — لا يُترك واضحاً في القاعدة ولا في النسخ الاحتياطية
        $key = (string) ($s->get('sms.key') ?? '');
        if ($key !== '') {
            try {
                $key = Crypt::decryptString($key);
            } catch (\Throwable $e) {
                // مفتاح تطبيق تغيّر أو قيمة تالفة — عطّل الإرسال بدل المحاولة بمفتاح خاطئ
                Log::warning('sms gateway key decrypt failed: ' . $e->getMessage());
                $key = '';
            }
        }

        $url = (string) ($s->get('sms.url') ?: config('services.sms.url', ''));
        $key = $key ?: (string) config('services.sms.key', '');

        // لا مفتاح «enabled» مخزَّن (تنصيب سابق لصفحة الإعدادات) => استنتجه من اكتمال الإعداد
        $enabled = $s->has('sms.enabled')
            ? $s->get('sms.enabled') === 'true'
            : ($url !== '' && $key !== '');

        return [
            'enabled' => $enabled,
            'url' => $url,
            'key' => $key,
            'sender' => (string) ($s->get('sms.sender_id') ?: config('services.sms.sender_id', 'Kafaat')),
            'support' => (string) ($s->get('sms.support_phone') ?? config('services.sms.support_phone', '')),
        ];
    }

    // ════════════════ خادم البريد (SMTP) ════════════════

    public const SMTP_KEYS = [
        'smtp.enabled', 'smtp.host', 'smtp.port', 'smtp.encryption',
        'smtp.username', 'smtp.password', 'smtp.from_address', 'smtp.from_name',
    ];

    // تعيين نوع التشفير إلى مخطّط Symfony:
    // smtps = TLS ضمني (٤٦٥)، smtp = اتصال عادي يرقّى بـSTARTTLS عند دعم الخادم (٥٨٧/٢٥)
    private const SCHEMES = ['ssl' => 'smtps', 'tls' => 'smtp', 'none' => 'smtp'];

    // ── إعداد خادم البريد: جدول settings أولاً، ثم متغيّرات البيئة ──
    public static function smtpConfig(): array
    {
        $s = Setting::whereIn('key', self::SMTP_KEYS)->pluck('value', 'key');

        $pass = (string) ($s->get('smtp.password') ?? '');
        if ($pass !== '') {
            try {
                $pass = Crypt::decryptString($pass);
            } catch (\Throwable $e) {
                // مفتاح تطبيق تغيّر أو قيمة تالفة — عطّل بدل المصادقة بكلمة خاطئة
                Log::warning('smtp password decrypt failed: ' . $e->getMessage());
                $pass = '';
            }
        }

        $host = (string) ($s->get('smtp.host') ?: config('mail.mailers.smtp.host', ''));
        $enc = (string) ($s->get('smtp.encryption') ?: 'tls');

        // لا مفتاح «enabled» مخزَّن (تنصيب سابق لهذه الصفحة) => لا تتدخّل، اترك mail.php للبيئة
        $enabled = $s->has('smtp.enabled') ? $s->get('smtp.enabled') === 'true' : false;

        return [
            'enabled' => $enabled,
            'host' => $host,
            'port' => (int) ($s->get('smtp.port') ?: config('mail.mailers.smtp.port', 587)),
            'encryption' => isset(self::SCHEMES[$enc]) ? $enc : 'tls',
            'username' => (string) ($s->get('smtp.username') ?? config('mail.mailers.smtp.username', '')),
            'password' => $pass ?: (string) config('mail.mailers.smtp.password', ''),
            'fromAddress' => (string) ($s->get('smtp.from_address') ?: config('mail.from.address', '')),
            'fromName' => (string) ($s->get('smtp.from_name') ?: config('mail.from.name', '')),
        ];
    }

    // ── تركيب المُرسِل من الإعدادات المحفوظة قبل كل إرسال ──
    // نضبط config ثم ننادي Mail::raw العادي، ولا نستعمل Mail::build:
    // MailFake لا يعرّف build() فيُمرّرها __call للمدير الحقيقي، فيفتح اختبارٌ
    // مُزيَّف اتصالاً فعلياً بخادم البريد. المسار هنا يبقى قابلاً للتزييف.
    private static function applySmtpConfig(): bool
    {
        $c = self::smtpConfig();
        if (!$c['enabled'] || $c['host'] === '') {
            return false; // اترك المُرسِل الافتراضي (log/array حسب البيئة)
        }

        config([
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => self::SCHEMES[$c['encryption']],
            'mail.mailers.smtp.host' => $c['host'],
            'mail.mailers.smtp.port' => $c['port'],
            'mail.mailers.smtp.username' => $c['username'] ?: null,
            'mail.mailers.smtp.password' => $c['password'] ?: null,
            // خادم بريد معلّق يجب ألا يوقف خيط الطلب إلى ما لا نهاية
            'mail.mailers.smtp.timeout' => 10,
            'mail.default' => 'smtp',
        ]);
        if ($c['fromAddress'] !== '') {
            config(['mail.from.address' => $c['fromAddress'], 'mail.from.name' => $c['fromName']]);
        }

        // المدير يخزّن المُرسِل بعد أول تركيب — بلا إبطال يبقى الإعداد القديم حيّاً
        Mail::purge('smtp');

        return true;
    }

    // ════════════════ البريد الإلكتروني ════════════════

    // ── إرسال دعوة بالبريد ──
    public function sendInvitationEmail(
        int $candidateId,
        string $toEmail,
        ?string $toName,
        array $data,
        ?int $createdBy
    ): bool {
        // جلب القالب من الإعدادات
        $subject = Setting::find('email.invitation.subject')?->value ?? 'دعوة لجلسة تقييم';
        $template = Setting::find('email.invitation.template')?->value
            ?? "التاريخ: {date}\nالوقت: {time}\nالمكان: {location}\nالمطلوب: {requirements}";

        // استبدال المتغيّرات
        $body = strtr($template, [
            '{newline}' => "\n",
            '{date}' => $data['date'] ?? '',
            '{time}' => $data['time'] ?? '',
            '{location}' => $data['location'] ?? '',
            '{requirements}' => $data['requirements'] ?? '',
        ]);

        return $this->sendEmail($toEmail, $toName, $subject, $body, 'invitation', $candidateId, $createdBy);
    }

    // ── إرسال بريد عام ──
    public function sendEmail(
        string $toEmail,
        ?string $toName,
        string $subject,
        string $body,
        string $emailType,
        ?int $candidateId,
        ?int $createdBy
    ): bool {
        // كتابة السجل داخل try — فشل التسجيل يُنهي الإرسال بلطف (false) بدل تصعيد 500 لطرف النداء
        try {
            $log = EmailLog::create([
                'to_email' => $toEmail,
                'to_name' => $toName,
                'subject' => $subject,
                'body' => $body,
                'email_type' => $emailType,
                'candidate_id' => $candidateId,
                'status' => 'pending',
                'created_by' => $createdBy,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('email log write failed: ' . $e->getMessage());
            return false;
        }

        try {
            // يركّب المُرسِل من إعدادات SMTP المحفوظة؛ وإن كانت معطّلة يبقى الافتراضي
            self::applySmtpConfig();

            Mail::raw($body, function ($message) use ($toEmail, $toName, $subject) {
                $message->to($toEmail, $toName)->subject($subject);
            });

            $log->update(['status' => 'sent', 'sent_at' => now()]);
            return true;
        } catch (\Throwable $e) {
            // Throwable لا Exception: أخطاء نقل Symfony قد تأتي كـError، وتسريبها
            // يُصعّد 500 لطرف النداء بدل الفشل اللطيف الذي يوثّقه السجل
            $log->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            return false;
        }
    }

    // ════════════════ الرسائل النصية ════════════════

    // ── إرسال دعوة برسالة نصية ──
    public function sendInvitationSms(int $candidateId, string $toMobile, array $data, ?int $createdBy): bool
    {
        $template = Setting::find('sms.invitation.template')?->value
            ?? 'لديك جلسة تقييم بتاريخ {date} الساعة {time}';
        $phone = self::gatewayConfig()['support'];

        $message = strtr($template, [
            '{date}' => $data['date'] ?? '',
            '{time}' => $data['time'] ?? '',
            '{location}' => $data['location'] ?? '',
            '{phone}' => $phone,
        ]);

        return $this->sendSms($toMobile, $message, 'invitation', $candidateId, $createdBy);
    }

    // ── إرسال رسالة نصية عامة ──
    public function sendSms(
        string $toMobile,
        string $message,
        string $smsType,
        ?int $candidateId,
        ?int $createdBy
    ): bool {
        // كتابة السجل داخل try — فشل التسجيل يُنهي الإرسال بلطف (false) بدل تصعيد 500 لطرف النداء
        try {
            $log = SmsLog::create([
                'to_mobile' => $toMobile,
                'message' => $message,
                'sms_type' => $smsType,
                'candidate_id' => $candidateId,
                'status' => 'pending',
                'created_by' => $createdBy,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('sms log write failed: ' . $e->getMessage());
            return false;
        }

        try {
            $g = self::gatewayConfig();

            if (!$g['enabled'] || $g['url'] === '' || $g['key'] === '') {
                // وضع التطوير: البوّابة معطّلة أو غير مكتملة
                $log->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'error_message' => '(وضع التطوير: لم تُرسل فعلياً - أعدّ بوّابة الرسائل)',
                ]);
                return true;
            }

            // الاتصال ببوّابة الرسائل (شكل عام يصلح لمعظم المزوّدين مثل Unifonic)
            // مهلة صريحة: بوّابة معلّقة يجب ألا توقف خيط الطلب حتى المهلة الافتراضية (30ث)
            $response = Http::asJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post($g['url'], [
                    'appSid' => $g['key'],
                    'sender' => $g['sender'],
                    'recipient' => $toMobile,
                    'body' => $message,
                ]);

            if ($response->successful()) {
                $log->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'provider_ref' => mb_substr($response->body(), 0, 100),
                ]);
                return true;
            }

            $log->update(['status' => 'failed', 'error_message' => 'HTTP ' . $response->status()]);
            return false;
        } catch (\Exception $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return false;
        }
    }
}
