<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\CandidateCv;
use App\Models\ReceptionKiosk;
use App\Models\ReceptionVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

// ════════════════════════════════════════════════════════════
//  كشك الاستقبال — الجهاز اللوحي في بهو المركز.
//
//  مسار المشارك عليه: يُدخل هويته ← يراجع بياناته ← يوقّع ويقرّ ← يُرسَل
//  أمر طباعة بطاقته إلى جهاز مسؤول المشاركين ← تعود الشاشة صفراً للتالي.
//
//  ما ينتجه هذا المتحكّم ليس سجلّاً منفصلاً: هو نفس ReceptionVisit التي
//  يصنعها ReceptionController بيد الموظّف. فالوصول والتوقيع يظهران فوراً
//  في كشف الاستقبال، ويكملان المسار القائم (توزيع ← قرار المقيّم ← اعتماد
//  ← ترحيل الجلسات). الكشك بابٌ آخر للبيانات نفسها، لا مسارٌ موازٍ.
//
//  ── نموذج التهديد ──
//  الجهاز في مكان عام، بلا جلسة موظّف، ويمرّ عليه غرباء. لذا:
//   • لا مصادقة نظام ⇒ لا صلاحيات: هذه المسارات الخمسة كل ما يملكه الرمز.
//   • لا بيان قبل مطابقة رقم الهوية — كبوّابة المشارك تماماً.
//   • رمز الكشك نطاقه يومٌ واحد وقابل للإبطال، فتسريبه خسارة يوم.
//   • رمز الجلسة (accessToken) في ذاكرة المتصفّح، عمره خمس دقائق: المشارك
//     يقف أمام الجهاز دقيقتين، ومن يجلس بعده لا يرث جلسة من قبله.
//   • حدّان للمعدّل: واحدٌ للكشك كلّه (طابور اليوم) وآخر لكل رقم هوية —
//     فسعةُ الكشك لا تصير سعةَ تخمينٍ لشخصٍ بعينه.
//   • لا كتابة على السيرة من هنا إطلاقاً: الكشك يعرض ويوقّع ولا يحرّر.
// ════════════════════════════════════════════════════════════

class KioskController extends Controller
{
    private const ACTIVITY_LABEL = [
        'interview' => 'المقابلة الشخصية',
        'discussion' => 'حلقة النقاش',
        'measurement' => 'أدوات القياس',
        'integration' => 'التمرين التكاملي',
    ];

    private const ACCESS_TTL = 300;         // عمر جلسة المشارك على الكشك: ٥ دقائق
    private const ID_ATTEMPTS = 5;          // محاولات لكل رقم هوية قبل قفله
    private const ID_LOCK_SECONDS = 900;    // ١٥ دقيقة
    private const KIOSK_ATTEMPTS = 200;     // سقف الكشك في الساعة (طابور يومٍ كامل)
    private const KIOSK_WINDOW = 3600;

    // ── حلّ الكشك من رمزه ──
    // الرفض السريع للرموز القصيرة قبل أي استعلام: رمزٌ من ثلاثة أحرف لا
    // يستحقّ رحلةً إلى القاعدة، ولا يُفتح به بابُ إغراق.
    private function kiosk(string $token): ?ReceptionKiosk
    {
        if (strlen($token) < 32) {
            return null;
        }
        $k = ReceptionKiosk::where('token', $token)->first();

        return $k && $k->isUsable() ? $k : null;
    }

    private function audit(Request $request, string $action, $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => null,                 // لا مستخدم: الكشك ليس موظّفاً
            'action' => $action,
            'entity_type' => 'kiosk',
            'entity_id' => (string) $entityId,
            'details' => $details ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function gone()
    {
        return response()->json(['error' => 'رابط الكشك غير صالح أو انتهت صلاحيته'], 404);
    }

    private function expired()
    {
        return response()->json(['error' => 'انتهت الجلسة — أعد إدخال رقم هويتك'], 401);
    }

    // ── رمز جلسة عديم الحالة (موقّع ومشفّر) — لا يُخزَّن ولا يُستعلَم عنه ──
    // مربوط بالكشك وبالدورة معاً: رمزٌ صدر على كشكٍ لا يعمل على آخر، ورمزٌ
    // صدر لمشاركٍ لا يفتح دورة غيره.
    private function issueAccess(ReceptionKiosk $k, Assessment $a): string
    {
        return Crypt::encryptString(json_encode([
            'kid' => $k->id,
            'aid' => $a->id,
            'exp' => now()->addSeconds(self::ACCESS_TTL)->timestamp,
        ]));
    }

    private function readAccess(Request $request, ReceptionKiosk $k): ?Assessment
    {
        $raw = (string) $request->input('accessToken');
        if ($raw === '') {
            return null;
        }
        try {
            $d = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return null;                        // تلاعب أو رمز من مفتاحٍ آخر
        }
        if (!is_array($d)) return null;
        if (($d['kid'] ?? null) !== $k->id) return null;
        if ((int) ($d['exp'] ?? 0) < now()->timestamp) return null;

        return Assessment::with(['candidate.sector', 'candidate.cv', 'schedules'])
            ->find($d['aid'] ?? 0);
    }

    // ═══════════════════════════════════════════════════════
    //  ٠) حالة الكشك — قبل أي هوية، فلا بيانات هنا إطلاقاً
    // ═══════════════════════════════════════════════════════
    public function show(Request $request, string $token)
    {
        $k = $this->kiosk($token);
        if (!$k) {
            return $this->gone();
        }

        return response()->json([
            'ready' => true,
            'date' => $k->kiosk_date->toDateString(),
            'label' => $k->label,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  ١) بوّابة الهوية — لا يُرجَع بيان قبل المطابقة
    // ═══════════════════════════════════════════════════════
    public function identify(Request $request, string $token)
    {
        $k = $this->kiosk($token);
        if (!$k) {
            return $this->gone();
        }

        $request->validate([
            'nationalId' => 'required|string|regex:/^\d{10}$/',
        ], [
            'nationalId.required' => 'أدخل رقم الهوية',
            'nationalId.regex' => 'رقم الهوية يجب أن يكون ١٠ أرقام',
        ]);

        // حدّ الكشك: يحمي من استعمال الجهاز آلةَ تعداد. واسعٌ عمداً — طابور
        // يومٍ كامل يمرّ من هنا، وحدٌّ ضيّق يوقف الاستقبال قبل أن يوقف مهاجماً.
        if (RateLimiter::hit('kiosk:' . $k->id, self::KIOSK_WINDOW) > self::KIOSK_ATTEMPTS) {
            $this->audit($request, 'KIOSK_RATE_LIMIT', $k->id);
            return response()->json(['error' => 'الكشك متوقّف مؤقتاً — راجع مسؤول الاستقبال'], 429);
        }

        // الحدّ الحاسم: لكل رقم هوية على حدة. بدونه تصير سعةُ الكشك الواسعة
        // سعةَ تخمينٍ لشخصٍ بعينه، وهو ما لا يمنعه الحدّ الأول أصلاً.
        // الزيادة قبل المقارنة (ذرّية) — تمنع تجاوز الحد بطلباتٍ متزامنة.
        $idHash = hash('sha256', $request->input('nationalId'));
        $idKey = 'kiosk:' . $k->id . ':id:' . substr($idHash, 0, 32);
        $hits = RateLimiter::hit($idKey, self::ID_LOCK_SECONDS);
        if ($hits > self::ID_ATTEMPTS) {
            $mins = ceil(RateLimiter::availableIn($idKey) / 60);
            return response()->json([
                'error' => "محاولات كثيرة. راجع مسؤول الاستقبال أو حاول بعد {$mins} دقيقة.",
                'locked' => true,
            ], 429);
        }

        // الدورة المستقبَلة اليوم: أحدث دورةٍ غير منتهية لصاحب هذه الهوية.
        // المنتهية لا تُستقبل — من أكمل تقييمه ليس حاضراً اليوم.
        $assessment = Assessment::with(['candidate.sector', 'candidate.cv', 'schedules'])
            ->whereHas('candidate', fn ($c) => $c->where('national_id_hash', $idHash))
            ->whereNotIn('status', ['completed'])
            ->orderByDesc('id')
            ->first();

        if (!$assessment) {
            // ردٌّ واحد لغير الموجود ولغير المتوقَّع اليوم: التمييز بينهما
            // يجعل الكشك يجيب «هل فلانٌ مرشَّح؟» لمن يعرف رقم هويته.
            $this->audit($request, 'KIOSK_IDENTIFY_FAIL', $k->id);
            return response()->json([
                'error' => 'لا يوجد موعد مسجّل بهذا الرقم — راجع مسؤول الاستقبال',
                'attemptsLeft' => max(0, self::ID_ATTEMPTS - $hits),
            ], 404);
        }

        RateLimiter::clear($idKey);              // مشاركٌ صحيح لا يُعاقَب
        $k->forceFill(['last_used_at' => now()])->save();
        $this->audit($request, 'KIOSK_IDENTIFY_OK', $k->id, ['code' => $assessment->participant_code]);

        $visit = $this->visitOf($k, $assessment);

        return response()->json([
            'accessToken' => $this->issueAccess($k, $assessment),
            'candidate' => $this->present($assessment, $visit),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  ٢) تسجيل الوصول — نفس زيارة كشف الاستقبال، لا سجلّ موازٍ
    // ═══════════════════════════════════════════════════════
    public function arrive(Request $request, string $token)
    {
        $k = $this->kiosk($token);
        if (!$k) return $this->gone();

        $a = $this->readAccess($request, $k);
        if (!$a) return $this->expired();

        $today = $k->kiosk_date->toDateString();

        $visit = DB::transaction(function () use ($a, $k, $today) {
            // firstOrCreate على القيد الفريد (assessment_id, visit_date):
            // نقرتان متتاليتان على الشاشة اللمسية تُنتجان زيارةً واحدة
            $visit = ReceptionVisit::firstOrCreate(
                ['assessment_id' => $a->id, 'visit_date' => $today],
                [
                    'candidate_id' => $a->candidate_id,
                    'arrived_at' => now(),
                    'received_by' => null,        // تسجيل ذاتي: لا موظّف سجّله
                    'kiosk_id' => $k->id,
                    'status' => ReceptionVisit::ARRIVED,
                ]
            );

            // assessments.arrived_at يبقى متّسقاً مع الزيارة — مصدران عن
            // وقتٍ واحد يختلفان أسوأ من مصدرٍ ناقص
            if ($visit->wasRecentlyCreated && $a->arrived_at === null) {
                $a->update(['arrived_at' => $visit->arrived_at]);
            }

            return $visit;
        });

        if ($visit->wasRecentlyCreated) {
            $this->audit($request, 'KIOSK_ARRIVE', $k->id, ['code' => $a->participant_code]);
        }

        return response()->json([
            'message' => $visit->wasRecentlyCreated ? 'تم تسجيل وصولك' : 'وصولك مسجَّل مسبقاً',
            'candidate' => $this->present($a->fresh(['candidate.sector', 'candidate.cv', 'schedules']), $visit),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  ٣) التوقيع والإقرار بصحّة البيانات
    // ═══════════════════════════════════════════════════════
    public function sign(Request $request, string $token)
    {
        $k = $this->kiosk($token);
        if (!$k) return $this->gone();

        $a = $this->readAccess($request, $k);
        if (!$a) return $this->expired();

        $request->validate([
            // نفس حدّ ReceptionController::sign — توقيعٌ عالي الدقّة يمرّ،
            // ورفعُ ملفٍ كبير عبر الحقل لا يمرّ
            'signature' => 'required|string|max:400000|starts_with:data:image/png;base64,',
            'attested' => 'required|accepted',
        ], [
            'signature.required' => 'وقّع في المساحة المخصّصة',
            'signature.starts_with' => 'صيغة التوقيع غير صالحة',
            'attested.accepted' => 'أقرّ بصحّة بياناتك قبل التوقيع',
        ]);

        $visit = ReceptionVisit::where('assessment_id', $a->id)
            ->whereDate('visit_date', $k->kiosk_date->toDateString())
            ->first();

        // التوقيع لا يسبق الوصول: الإقرار وثيقةُ حضورٍ في يومٍ بعينه
        if (!$visit) {
            return response()->json(['error' => 'سجّل وصولك أولاً'], 422);
        }
        // إقرارٌ لا يُعاد بعد الاعتماد: استبداله يجعل الموقَّع غير المعتمَد
        if ($visit->status === ReceptionVisit::APPROVED) {
            return response()->json(['error' => 'اكتملت إجراءاتك — راجع مسؤول الاستقبال'], 422);
        }

        $visit->signature = $request->input('signature');
        $visit->attested = true;
        $visit->signed_at = now();
        $visit->save();

        // التوقيع نفسه لا يدخل سجل التدقيق: بيانات شخصية، ونسخةٌ منها في
        // السجلّ نسخةٌ خارج التشفير
        $this->audit($request, 'KIOSK_SIGN', $k->id, ['code' => $a->participant_code]);

        return response()->json([
            'message' => 'تم توقيعك وإقرارك بصحّة البيانات',
            'candidate' => $this->present($a->fresh(['candidate.sector', 'candidate.cv', 'schedules']), $visit->fresh()),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  ٤) أمر طباعة البطاقة — يدخل طابور جهاز مسؤول المشاركين
    // ═══════════════════════════════════════════════════════
    public function badge(Request $request, string $token)
    {
        $k = $this->kiosk($token);
        if (!$k) return $this->gone();

        $a = $this->readAccess($request, $k);
        if (!$a) return $this->expired();

        $visit = ReceptionVisit::where('assessment_id', $a->id)
            ->whereDate('visit_date', $k->kiosk_date->toDateString())
            ->first();

        // البطاقة ثمرةُ الإقرار: طباعتها قبل التوقيع تُخرج مشاركاً في القاعة
        // لم يقرّ بصحّة بياناته بعد
        if (!$visit || !$visit->isSigned()) {
            return response()->json(['error' => 'وقّع وأقرّ بصحّة بياناتك أولاً'], 422);
        }

        // الطلب يُسجَّل مرّة: نقرةٌ ثانية لا تُنتج بطاقتين على الطابعة.
        // إعادة الطباعة بابها جهازُ المسؤول لا الكشك.
        if (!$visit->badgePending() && $visit->badge_printed_at === null) {
            $visit->update(['badge_requested_at' => now()]);
            $this->audit($request, 'KIOSK_BADGE_REQUEST', $k->id, ['code' => $a->participant_code]);
        }

        return response()->json([
            'message' => 'أُرسل أمر الطباعة — استلم بطاقتك من مسؤول الاستقبال',
            'badge' => $this->badgePayload($a),
            'alreadyPrinted' => $visit->badge_printed_at !== null,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  العرض
    // ═══════════════════════════════════════════════════════

    private function visitOf(ReceptionKiosk $k, Assessment $a): ?ReceptionVisit
    {
        return ReceptionVisit::where('assessment_id', $a->id)
            ->whereDate('visit_date', $k->kiosk_date->toDateString())
            ->first();
    }

    // ما يراه المشارك عن نفسه قبل أن يوقّع. الإقرار لا يكون إقراراً إن وقّع
    // على بياناتٍ لم يرها، فتُعرَض هنا كاملةً كما هي في ملفّه — لا ملخّصاً.
    private function present(Assessment $a, ?ReceptionVisit $visit): array
    {
        $c = $a->candidate;
        $cv = $c->cv?->data;

        return [
            'name' => $c->full_name,
            'nationalIdMasked' => $this->maskId($c->national_id),
            'participantCode' => $a->participant_code,
            'sector' => $c->sector?->name_ar,
            'rank' => $c->rank_label,
            'tier' => $c->tier === 'upper' ? 'قيادة عليا' : 'قيادة وسطى',
            'assessmentType' => Assessment::typeLabel($a->assessment_type),
            // البيانات الوظيفية من السيرة — هي محلّ الإقرار عملياً
            'cv' => $cv && !CandidateCv::isEmptyDoc($cv) ? [
                'department' => $cv['department'] ?? null,
                'region' => $cv['region'] ?? null,
                'rankLabel' => $cv['rankLabel'] ?? null,
                'currentPosition' => $cv['currentPosition'] ?? null,
                'birthDate' => $cv['birthDate'] ?? null,
                'appointmentDate' => $cv['appointmentDate'] ?? null,
                'age' => CandidateCv::ageFrom($cv['birthDate'] ?? null),
                'totalYearsExperience' => $cv['totalYearsExperience'] ?? 0,
                'qualifications' => count($cv['qualifications'] ?? []),
                'experiences' => count($cv['experiences'] ?? []),
                'certifications' => count($cv['certifications'] ?? []),
            ] : null,
            'schedules' => $this->schedules($a),
            'arrived' => $visit !== null,
            'arrivedAt' => $visit?->arrived_at?->format('H:i'),
            'signed' => (bool) $visit?->isSigned(),
            'badgeRequested' => (bool) $visit?->badge_requested_at,
            'badgePrinted' => (bool) $visit?->badge_printed_at,
        ];
    }

    // البطاقة بلا اسم عمداً — تُبرَز في القاعة أمام المقيّمين، والتقييم
    // يجري دون معرفة الاسم. رمز المشارك هو هويتها.
    private function badgePayload(Assessment $a): array
    {
        return [
            'participantCode' => $a->participant_code,
            'sector' => $a->candidate?->sector?->name_ar,
            'assessmentType' => Assessment::typeLabel($a->assessment_type),
            'schedules' => $this->schedules($a),
        ];
    }

    private function schedules(Assessment $a): array
    {
        return $a->schedules
            ->sortBy(fn ($s) => substr((string) $s->schedule_date, 0, 10) . ' ' . $s->schedule_time)
            ->values()
            ->map(fn ($s) => [
                'date' => substr((string) $s->schedule_date, 0, 10),
                'time' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null,
                'activity' => self::ACTIVITY_LABEL[$s->activity] ?? $s->activity,
                'location' => $s->location,
            ])->all();
    }

    // رقم الهوية مقنّعاً: يطمئن المشارك أنه هو، ولا يعرض الرقم كاملاً على
    // شاشةٍ في بهوٍ عام يقف خلفها آخرون
    private function maskId(?string $id): ?string
    {
        if (!$id || strlen($id) < 4) return null;

        return str_repeat('•', strlen($id) - 4) . substr($id, -4);
    }
}
