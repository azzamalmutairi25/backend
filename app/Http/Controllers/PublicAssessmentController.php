<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════
//  بوابة المرشح العامة (بدون مصادقة) — عبر رمز فريد في الرسالة النصية
//  تأكيد البيانات + تسجيل الوصول الذاتي (ينعكس على الحضور)
// ════════════════════════════════════════════════════════════

class PublicAssessmentController extends Controller
{
    private const ACTIVITY_LABEL = [
        'interview' => 'المقابلة الشخصية',
        'discussion' => 'حلقة النقاش',
        'presentation' => 'العرض التقديمي',
        'measurement' => 'أدوات القياس',
    ];

    // تطبيع التاريخ إلى Y-m-d سواء كان Carbon (cast) أو نصاً
    private function dateStr($value): ?string
    {
        if (!$value) return null;
        return substr((string) $value, 0, 10);
    }

    private function resolve(string $token): ?Assessment
    {
        if (strlen($token) < 20) return null; // رفض السريع للرموز القصيرة
        return Assessment::with(['candidate.sector', 'schedules'])
            ->where('confirm_token', $token)
            ->first();
    }

    // بيانات مصغّرة تُعرض للمرشح ليؤكدها
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

    // GET /public/assessment/{token}
    public function show(string $token)
    {
        $a = $this->resolve($token);
        if (!$a) {
            return response()->json(['error' => 'الرابط غير صالح أو منتهي'], 404);
        }
        return response()->json(['assessment' => $this->present($a)]);
    }

    // POST /public/assessment/{token}/confirm
    public function confirm(string $token)
    {
        $a = $this->resolve($token);
        if (!$a) {
            return response()->json(['error' => 'الرابط غير صالح أو منتهي'], 404);
        }
        if (!$a->confirmed_at) {
            $a->update(['confirmed_at' => now()]);
        }
        return response()->json([
            'message' => 'تم تأكيد بياناتك بنجاح',
            'assessment' => $this->present($a->fresh(['candidate.sector', 'schedules'])),
        ]);
    }

    // POST /public/assessment/{token}/arrive — تسجيل الوصول الذاتي → حضور
    public function arrive(string $token)
    {
        $a = $this->resolve($token);
        if (!$a) {
            return response()->json(['error' => 'الرابط غير صالح أو منتهي'], 404);
        }

        $today = now()->toDateString();
        $marked = 0;

        DB::transaction(function () use ($a, $today, &$marked) {
            if (!$a->arrived_at) {
                $a->arrived_at = now();
            }
            if (!$a->confirmed_at) {
                $a->confirmed_at = now(); // الوصول يؤكد البيانات ضمناً
            }
            $a->save();

            // علّم حضور جلسات اليوم التي لا حضور لها بعد (احترام قاعدة المرّة الواحدة)
            foreach ($a->schedules as $s) {
                if ($this->dateStr($s->schedule_date) !== $today) continue;
                $exists = Attendance::where('schedule_id', $s->id)->exists();
                if ($exists) continue;
                Attendance::create([
                    'schedule_id' => $s->id,
                    'status' => 'present',
                    'check_in_time' => now(),
                    'recorded_by' => null, // تسجيل ذاتي من المرشح
                ]);
                $marked++;
            }
        });

        return response()->json([
            'message' => $marked > 0
                ? "تم تسجيل وصولك وحضور {$marked} جلسة"
                : 'تم تسجيل وصولك',
            'markedSessions' => $marked,
            'assessment' => $this->present($a->fresh(['candidate.sector', 'schedules'])),
        ]);
    }
}
