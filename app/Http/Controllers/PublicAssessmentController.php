<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Attendance;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

// ════════════════════════════════════════════════════════════
//  بوابة المرشح العامة (بدون مصادقة نظام) — عبر رمز فريد في الرسالة
//  أمان: لا تُكشف أي بيانات قبل إثبات معرفة رقم الهوية.
//  عاملان: رابط تملكه (something you have) + هوية تعرفها (something you know)
// ════════════════════════════════════════════════════════════

class PublicAssessmentController extends Controller
{
    private const ACTIVITY_LABEL = [
        'interview' => 'المقابلة الشخصية',
        'discussion' => 'حلقة النقاش',
        'measurement' => 'أدوات القياس',
        'integration' => 'التمرين التكاملي',
    ];

    private const MAX_ATTEMPTS = 5;      // محاولات التحقق قبل القفل
    private const LOCK_SECONDS = 900;    // ١٥ دقيقة قفل
    private const ACCESS_TTL = 1200;     // صلاحية جلسة الوصول ٢٠ دقيقة

    // تطبيع التاريخ إلى Y-m-d سواء كان Carbon (cast) أو نصاً
    private function dateStr($value): ?string
    {
        if (!$value) return null;
        return substr((string) $value, 0, 10);
    }

    private function resolve(string $token): ?Assessment
    {
        if (strlen($token) < 20) return null; // رفض سريع للرموز القصيرة
        return Assessment::with(['candidate.sector', 'schedules'])
            ->where('confirm_token', $token)
            ->first();
    }

    // بيانات مصغّرة تُعرض بعد التحقق فقط (post-auth)
    private function present(Assessment $a): array
    {
        return [
            'name' => $a->candidate->full_name,
            'participantCode' => $a->participant_code,
            'sectorName' => optional($a->candidate->sector)->name_ar,
            'assessmentType' => $a->assessment_type === 'executive' ? 'تنفيذي' : 'شامل',
            'confirmed' => (bool) $a->confirmed_at,
            'arrived' => (bool) $a->arrived_at,
            'confirmedAt' => optional($a->confirmed_at)->toIso8601String(),
            'arrivedAt' => optional($a->arrived_at)->toIso8601String(),
            'schedules' => $a->schedules
                ->sortBy(fn ($s) => $this->dateStr($s->schedule_date) . ' ' . $s->schedule_time)
                ->values()
                ->map(fn ($s) => [
                    'date' => $this->dateStr($s->schedule_date),
                    'time' => $s->schedule_time ? substr((string) $s->schedule_time, 0, 5) : null,
                    'activity' => self::ACTIVITY_LABEL[$s->activity] ?? $s->activity,
                    'location' => $s->location,
                ]),
        ];
    }

    // إصدار رمز جلسة عديم الحالة (موقّع ومشفّر AES بواسطة Crypt) — لا يُخزَّن
    private function issueAccessToken(Assessment $a): string
    {
        return Crypt::encryptString(json_encode([
            'aid' => $a->id,
            'exp' => now()->addSeconds(self::ACCESS_TTL)->timestamp,
        ]));
    }

    // التحقق من رمز الجلسة لكل إجراء لاحق (بدون إعادة إرسال الهوية)
    private function checkAccess(Request $request, Assessment $a): bool
    {
        $raw = (string) $request->input('accessToken');
        if ($raw === '') return false;
        try {
            $data = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable $e) {
            return false; // تلاعب أو رمز غير صالح
        }
        if (!is_array($data)) return false;
        if (($data['aid'] ?? null) !== $a->id) return false;   // الرمز أُصدر لدورة أخرى
        if ((int) ($data['exp'] ?? 0) < now()->timestamp) return false; // انتهت الصلاحية
        return true;
    }

    private function audit(Request $request, Assessment $a, string $action): void
    {
        AuditLog::create([
            'user_id' => null,
            'action' => $action,
            'entity_type' => 'assessment',
            'entity_id' => (string) $a->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    // POST /public/assessment/{token}/verify  { nationalId }
    // بوابة الأمان: لا يُرجَع أي بيان إلا بمطابقة الهوية
    public function verify(Request $request, string $token)
    {
        $request->validate([
            'nationalId' => 'required|string|regex:/^\d{10}$/',
        ], [
            'nationalId.required' => 'أدخل رقم الهوية',
            'nationalId.regex' => 'رقم الهوية يجب أن يكون ١٠ أرقام',
        ]);

        // قفل التخمين — الزيادة أولاً (ذرّية): تمنع تجاوز الحد عبر طلبات متزامنة (TOCTOU)
        // فالعدّاد يُزاد قبل المقارنة، والزيادة الذرّية في القاعدة تسلسل المتسابقين
        $rlKey = 'pubverify:' . sha1($token);
        $hits = RateLimiter::hit($rlKey, self::LOCK_SECONDS);
        if ($hits > self::MAX_ATTEMPTS) {
            $mins = ceil(RateLimiter::availableIn($rlKey) / 60);
            return response()->json([
                'error' => "محاولات كثيرة. حاول بعد {$mins} دقيقة.",
                'locked' => true,
            ], 429);
        }

        $a = $this->resolve($token);
        // مقارنة ثابتة الزمن ضد رابط غير صالح أو هوية غير مطابقة (نفس الرد لتفادي التعداد)
        $inputHash = hash('sha256', $request->nationalId);
        $stored = $a ? (string) $a->candidate->national_id_hash : '';
        $match = $a && hash_equals($stored, $inputHash);

        if (!$match) {
            if ($a) $this->audit($request, $a, 'PUBLIC_VERIFY_FAIL');
            return response()->json([
                'error' => 'رقم الهوية غير مطابق لهذا الرابط.',
                'attemptsLeft' => max(0, self::MAX_ATTEMPTS - $hits),
            ], 403);
        }

        RateLimiter::clear($rlKey); // نجاح ← لا نعاقب المرشح الشرعي
        $this->audit($request, $a, 'PUBLIC_VERIFY_OK');

        return response()->json([
            'assessment' => $this->present($a),
            'accessToken' => $this->issueAccessToken($a),
        ]);
    }

    // POST /public/assessment/{token}/confirm  { accessToken }
    public function confirm(Request $request, string $token)
    {
        $a = $this->resolve($token);
        if (!$a || !$this->checkAccess($request, $a)) {
            return response()->json(['error' => 'انتهت الجلسة — أعد إدخال رقم الهوية'], 401);
        }

        $already = (bool) $a->confirmed_at;
        if (!$already) {
            $a->update(['confirmed_at' => now()]);
            $this->audit($request, $a, 'PUBLIC_CONFIRM');
        }

        return response()->json([
            'message' => $already ? 'بياناتك مؤكّدة مسبقاً' : 'تم تأكيد بياناتك بنجاح',
            'alreadyConfirmed' => $already,
            'assessment' => $this->present($a->fresh(['candidate.sector', 'schedules'])),
        ]);
    }

    // POST /public/assessment/{token}/arrive  { accessToken } — تسجيل الوصول الذاتي → حضور
    public function arrive(Request $request, string $token)
    {
        $a = $this->resolve($token);
        if (!$a || !$this->checkAccess($request, $a)) {
            return response()->json(['error' => 'انتهت الجلسة — أعد إدخال رقم الهوية'], 401);
        }

        $today = now()->toDateString();
        $marked = 0;

        DB::transaction(function () use ($a, $today, &$marked) {
            if (!$a->arrived_at) $a->arrived_at = now();
            if (!$a->confirmed_at) $a->confirmed_at = now(); // الوصول يؤكد ضمناً
            $a->save();

            foreach ($a->schedules as $s) {
                if ($this->dateStr($s->schedule_date) !== $today) continue;
                // insertOrIgnore (ON CONFLICT DO NOTHING) — يحسم السباق المتزامن بلا استثناء يُفسد المعاملة على Postgres
                $inserted = Attendance::insertOrIgnore([
                    'schedule_id' => $s->id,
                    'status' => 'present',
                    'check_in_time' => now(),
                    'recorded_by' => null, // تسجيل ذاتي
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($inserted) $marked++;
            }
        });

        $this->audit($request, $a, 'PUBLIC_ARRIVE');

        return response()->json([
            'message' => $marked > 0 ? "تم تسجيل وصولك وحضور {$marked} جلسة" : 'تم تسجيل وصولك',
            'markedSessions' => $marked,
            'assessment' => $this->present($a->fresh(['candidate.sector', 'schedules'])),
        ]);
    }
}
