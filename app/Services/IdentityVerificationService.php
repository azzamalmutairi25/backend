<?php

namespace App\Services;

use App\Models\IdentityVerification;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ════════════════════════════════════════════════════════════
//  بوّابة التحقق من هوية المرشّح (يقين/أبشر أو أي مزوّد خارجي).
//  تكامل خارجي على نمط بوّابة الرسائل: عنوان + مفتاح مشفّر، يُضبط من
//  شاشة الإعدادات ويُختبَر. المفتاح لا يُترك واضحاً في القاعدة ولا النسخ.
//
//  الشكل العام (POST {url} مع رأس Authorization) يصلح لمعظم المزوّدين؛
//  عدّل خريطة الطلب/الردّ لمزوّدك إن لزم — كما في CommunicationService.
// ════════════════════════════════════════════════════════════

class IdentityVerificationService
{
    public const GATEWAY_KEYS = [
        'idverify.enabled', 'idverify.url', 'idverify.key', 'idverify.app_id', 'idverify.provider',
    ];

    // المزوّدون المدعومون: generic (شكل عام) و yakeen (قالب أبشر/يقين — اضبط العقد).
    public const PROVIDERS = ['generic', 'yakeen'];

    // ── إعداد البوّابة: جدول settings أولاً ثم متغيّرات البيئة ──
    // key_error: مفتاح مُخزَّن لكن فكّ تشفيره فشل (APP_KEY مختلف) — فشلٌ حقيقي
    // لا «غير مُعَدّ»، يُميَّز كي لا نُبلّغ نجاحاً زائفاً عند التبديل لموقع التعافي.
    public static function config(): array
    {
        $s = Setting::whereIn('key', self::GATEWAY_KEYS)->pluck('value', 'key');

        $storedKey = (string) ($s->get('idverify.key') ?? '');
        $key = '';
        $keyError = false;
        if ($storedKey !== '') {
            try {
                $key = Crypt::decryptString($storedKey);
            } catch (\Throwable $e) {
                Log::error('idverify key decrypt failed (APP_KEY mismatch?): ' . $e->getMessage());
                $keyError = true;
            }
        }

        $url = (string) ($s->get('idverify.url') ?: config('services.idverify.url', ''));
        $key = $key ?: (string) config('services.idverify.key', '');
        $appId = (string) ($s->get('idverify.app_id') ?: config('services.idverify.app_id', ''));
        $provider = (string) ($s->get('idverify.provider') ?: config('services.idverify.provider', 'generic'));
        if (! in_array($provider, self::PROVIDERS, true)) {
            $provider = 'generic';
        }

        $enabled = $s->has('idverify.enabled')
            ? $s->get('idverify.enabled') === 'true'
            : ($url !== '' && $key !== '');

        return [
            'enabled' => $enabled,
            'url' => $url,
            'key' => $key,
            'key_error' => $keyError,
            'app_id' => $appId,
            'provider' => $provider,
        ];
    }

    public static function isConfigured(): bool
    {
        $g = self::config();

        return $g['enabled'] && $g['url'] !== '' && $g['key'] !== '' && empty($g['key_error']);
    }

    // ── التحقق من رقم هوية عبر المزوّد ──
    // يرجع: ['ok'=>bool اكتمل الاتصال, 'matched'=>?bool طابق, 'message'=>نص, 'devMode'=>bool]
    // غير مُعَدّ = وضع تطوير (ok=true, matched=null) — لا يُعطّل التدفّق ولا يدّعي تحقّقاً.
    public function verify(string $nationalId): array
    {
        $g = self::config();

        if (! empty($g['key_error'])) {
            return ['ok' => false, 'matched' => null, 'devMode' => false,
                'message' => 'فشل فكّ تشفير مفتاح بوّابة التحقق (تحقّق من تطابق APP_KEY)'];
        }

        if (!$g['enabled'] || $g['url'] === '' || $g['key'] === '') {
            return ['ok' => true, 'matched' => null, 'devMode' => true,
                'message' => 'وضع التطوير: بوّابة التحقق غير مُعَدّة'];
        }

        try {
            $req = Http::asJson()->withToken($g['key'])->connectTimeout(5)->timeout(10);
            // ترويسات إضافية حسب المزوّد (يقين يطلب معرّف التطبيق في ترويسة)
            if ($g['provider'] === 'yakeen' && $g['app_id'] !== '') {
                $req = $req->withHeaders(['X-App-Id' => $g['app_id']]);
            }
            $response = $req->post($g['url'], $this->buildPayload($g, $nationalId));
        } catch (\Throwable $e) {
            Log::warning('idverify request failed: ' . $e->getMessage());
            return ['ok' => false, 'matched' => null, 'devMode' => false,
                'message' => 'تعذّر الاتصال ببوّابة التحقق'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'matched' => null, 'devMode' => false,
                'message' => 'ردّت البوّابة بخطأ HTTP ' . $response->status()];
        }

        $matched = $this->extractMatched($g['provider'], $response->json());

        return ['ok' => true, 'matched' => $matched, 'devMode' => false,
            'message' => $matched ? 'تطابقت الهوية' : 'لم تتطابق الهوية'];
    }

    // شكل الطلب حسب المزوّد. yakeen قالب مبدئي — اضبط أسماء الحقول لعقد أبشر الفعلي.
    private function buildPayload(array $g, string $nationalId): array
    {
        return match ($g['provider']) {
            'yakeen' => array_filter([
                'chargeCode' => $g['app_id'] ?: null,
                'nin' => $nationalId,          // رقم الهوية الوطنية في يقين
            ]),
            default => array_filter([
                'appId' => $g['app_id'] ?: null,
                'nationalId' => $nationalId,
            ]),
        };
    }

    // استخراج «تطابق» من ردّ المزوّد. عدّل المسارات لعقدك الفعلي.
    private function extractMatched(string $provider, $body): bool
    {
        return match ($provider) {
            // يقين: غالباً يرجع بيانات الشخص عند التطابق — نعدّه تطابقاً إن وُجد اسم/حالة
            'yakeen' => (bool) (
                data_get($body, 'matched')
                ?? data_get($body, 'isMatch')
                ?? data_get($body, 'data.firstName')  // وجود بيانات = هوية صالحة
                ?? false
            ),
            default => (bool) (
                data_get($body, 'matched')
                ?? data_get($body, 'verified')
                ?? data_get($body, 'valid')
                ?? data_get($body, 'data.matched')
                ?? false
            ),
        };
    }

    // ── تحقق + تسجيل السجلّ التدقيقي — يُستدعى من تدفّق إضافة المرشّح ──
    // fail-open: فشل البوّابة لا يوقف التدفّق، بل يُسجَّل status=failed ويُرجَع.
    public function verifyAndLog(string $nationalId, ?int $candidateId, ?int $checkedBy): array
    {
        $g = self::config();
        $result = $this->verify($nationalId);

        $status = match (true) {
            $result['devMode'] => 'dev_mode',
            ! $result['ok'] => 'failed',
            $result['matched'] === true => 'matched',
            default => 'not_matched',
        };

        try {
            IdentityVerification::create([
                'candidate_id' => $candidateId,
                'status' => $status,
                'provider' => $g['provider'],
                'detail' => mb_substr((string) $result['message'], 0, 255),
                'checked_by' => $checkedBy,
            ]);
        } catch (\Throwable $e) {
            Log::warning('idverify log write failed: ' . $e->getMessage());
        }

        return $result + ['status' => $status];
    }
}
