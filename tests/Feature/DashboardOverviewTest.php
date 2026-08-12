<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalReport;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// لوحة البداية: تُرجع 200 لكل دور، وتحجب الأقسام فرادى بالصلاحية،
// ولا تتجاوز أبداً حدّ القطاع الذي تحصر به شاشات القوائم.
class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluatorUser(string $sectorCode = 'ED'): User
    {
        return User::create([
            'username' => 'ev_' . substr(md5(uniqid('', true)), 0, 6),
            'full_name' => 'مقيّم',
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    // مرشّح كامل الأثر: تقييم مُسلَّم بدرجة كفاءة + تقرير + جلسة اليوم مع حضور
    private function fullCandidate(
        string $sectorCode,
        int $evaluatorId,
        Competency $comp,
        int $score = 4,
        string $reportStatus = 'approved',
        string $candidateStatus = 'completed',
        ?string $attendanceStatus = 'present'
    ): array {
        [$c, $a] = $this->makeCandidate([
            'sectorCode' => $sectorCode,
            'status' => $candidateStatus,
            'tier' => 'upper',
        ]);

        $ev = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $evaluatorId,
            'activity' => 'interview', 'status' => 'approved', 'submitted_at' => now(),
        ]);
        EvaluationScore::create(['evaluation_id' => $ev->id, 'competency_id' => $comp->id, 'score' => $score]);

        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => $reportStatus,
            'behavioral_fit' => 80, 'technical_fit' => 70, 'created_by' => null,
        ]);

        $s = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '09:00',
            'activity' => 'interview', 'evaluator_id' => $evaluatorId,
        ]);
        if ($attendanceStatus !== null) {
            Attendance::create(['schedule_id' => $s->id, 'status' => $attendanceStatus, 'recorded_by' => null]);
        }

        return [$c, $a, $ev, $s];
    }

    // ── (أ) مدير النظام: كل قسم حاضر ──

    public function test_admin_gets_every_section_populated(): void
    {
        $this->actingAsRole('ADMIN');
        $ev = $this->evaluatorUser();
        $comp = Competency::orderBy('sort_order')->first();

        $this->fullCandidate('ED', $ev->id, $comp, 4);
        $this->fullCandidate('ED', $ev->id, $comp, 5);
        $this->fullCandidate('HI', $ev->id, $comp, 3, 'pending_evaluator', 'assessed', 'absent_unexcused');

        $res = $this->getJson('/api/dashboard/overview')->assertOk();

        $res->assertJsonStructure([
            'generatedAt',
            'kpis' => [
                'candidates' => ['value', 'unit', 'delta'],
                'attendance' => ['value', 'unit', 'delta'],
                'completion' => ['value', 'unit', 'delta'],
                'evaluations' => ['value', 'unit', 'delta'],
                'approvals' => ['value', 'unit', 'delta'],
                'pending' => ['value', 'unit', 'delta'],
            ],
            'readiness' => ['value', 'deltaPoints'],
            'trend' => ['months'],
            'attendanceToday' => ['total', 'present', 'absent', 'pending'],
            'weekHeatmap' => ['days', 'rows'],
            'todaySchedule',
            'insights',
        ]);

        // لا قسم محجوب عن مدير النظام
        foreach (['kpis', 'readiness', 'trend', 'attendanceToday', 'weekHeatmap', 'todaySchedule', 'insights'] as $k) {
            $this->assertNotNull($res->json($k), $k);
        }
        foreach (['candidates', 'attendance', 'completion', 'evaluations', 'approvals', 'pending'] as $k) {
            $this->assertNotNull($res->json("kpis.{$k}"), $k);
        }

        // الأرقام
        $this->assertSame(3, $res->json('kpis.candidates.value'));
        $this->assertSame(3, $res->json('kpis.evaluations.value'));
        $this->assertSame(2, $res->json('kpis.approvals.value'));
        $this->assertSame(1, $res->json('kpis.pending.value'));
        // مكتمل ٢ من ٣ ← ٦٧٪
        $this->assertSame(67, $res->json('kpis.completion.value'));
        $this->assertSame('%', $res->json('kpis.completion.unit'));
        // حضر ٢ من ٣ جلسات اليوم ← ٦٧٪
        $this->assertSame(67, $res->json('kpis.attendance.value'));
        // ارتفاع طابور الاعتماد سيّئ — الواجهة تلوّنه تحذيراً
        $this->assertTrue($res->json('kpis.pending.delta.inverse'));
        $this->assertFalse($res->json('kpis.candidates.delta.inverse'));

        // الجاهزية = (80+70)/2 = 75
        $this->assertEquals(75, $res->json('readiness.value'));

        // اتجاه ١٢ شهراً، الأقدم أولاً، وآخره الشهر الحالي
        $months = $res->json('trend.months');
        $this->assertCount(12, $months);
        $this->assertSame(now()->format('Y-m'), $months[11]['month']);
        $this->assertSame(now()->copy()->subMonths(11)->format('Y-m'), $months[0]['month']);
        $this->assertNotEmpty($months[0]['label']);          // اسم شهر عربي
        $this->assertSame(3, $months[11]['evaluations']);
        $this->assertSame(2, $months[11]['approvedReports']);

        // حضور اليوم يطابق مؤشّر الحضور
        $this->assertSame(['total' => 3, 'present' => 2, 'absent' => 1, 'pending' => 0], $res->json('attendanceToday'));

        // الخريطة: سبعة أيام بمفاتيح ٠..٦ (الأحد أولاً) وسبع خلايا لكل صفّ
        $days = $res->json('weekHeatmap.days');
        $this->assertCount(7, $days);
        $this->assertSame(range(0, 6), array_column($days, 'key'));
        $rows = $res->json('weekHeatmap.rows');
        $this->assertNotEmpty($rows);
        $this->assertLessThanOrEqual(6, count($rows));
        foreach ($rows as $row) {
            $this->assertCount(7, $row['cells']);
            $filled = array_values(array_filter($row['cells'], fn ($c) => $c !== null));
            $this->assertNotEmpty($filled);
            // خليّة بلا عيّنة = null لا صفر
            $this->assertLessThan(7, count($filled));
            foreach ($filled as $cell) {
                $this->assertLessThanOrEqual(100, $cell['pct']);
                $this->assertGreaterThan(0, $cell['samples']);
            }
        }

        // جدول اليوم: بلا أي اسم — نشاطٌ وفئةٌ قيادية فقط
        $schedule = $res->json('todaySchedule.items');
        $this->assertNotEmpty($schedule);
        $this->assertLessThanOrEqual(6, count($schedule));
        $this->assertSame('09:00', $schedule[0]['time']);
        $this->assertSame('مقابلة شخصية — القيادة العليا', $schedule[0]['title']);
        $this->assertContains($schedule[0]['tone'], ['accent', 'info', 'purple', 'warn']);
        foreach ($schedule as $row) {
            $this->assertStringNotContainsString('مرشح اختبار', $row['title']);
        }

        // العدّ الكلّي مستقلٌّ عن القصّ — وإلا ثبت الرقم المعروض على ٦
        $this->assertGreaterThanOrEqual(count($schedule), $res->json('todaySchedule.total'));

        $this->assertLessThanOrEqual(4, count($res->json('insights')));
    }

    // ── يومٌ بلا جلسات: نسبة الحضور مجهولة لا صفر ──
    // الصفر كان يُقرأ «لم يحضر أحد»، فيصير كلُّ يوم عطلة إنذارَ انهيارٍ أحمر
    public function test_attendance_rate_is_null_not_zero_when_today_has_no_sessions(): void
    {
        $this->actingAsRole('ADMIN');
        $ev = $this->evaluatorUser();
        $comp = Competency::orderBy('sort_order')->first();

        // جلسات في الأسبوع الماضي فقط — فترةُ مقارنة موجودة، ولا جلسة اليوم
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'ED', 'tier' => 'upper']);
        $past = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->subDays(2)->toDateString(), 'schedule_time' => '09:00',
            'activity' => 'interview', 'evaluator_id' => $ev->id,
        ]);
        Attendance::create(['schedule_id' => $past->id, 'status' => 'present', 'recorded_by' => null]);

        $res = $this->getJson('/api/dashboard/overview')->assertOk();

        $this->assertNull($res->json('kpis.attendance.value'), 'يوم بلا جلسات نسبتُه مجهولة لا صفر');
        $this->assertNull($res->json('kpis.attendance.delta'), 'لا مقارنة إلا بطرفين معلومين');
    }

    // ── جدول اليوم: العدّ الكلّي لا يتقيّد بحدّ العرض ──
    public function test_today_schedule_total_counts_beyond_the_display_cap(): void
    {
        $this->actingAsRole('ADMIN');
        $ev = $this->evaluatorUser();

        for ($i = 0; $i < 9; $i++) {
            [$c, $a] = $this->makeCandidate(['sectorCode' => 'ED', 'tier' => 'upper']);
            Schedule::create([
                'candidate_id' => $c->id, 'assessment_id' => $a->id,
                'schedule_date' => now()->toDateString(),
                'schedule_time' => sprintf('%02d:00', 8 + $i),
                'activity' => 'interview', 'evaluator_id' => $ev->id,
            ]);
        }

        $res = $this->getJson('/api/dashboard/overview')->assertOk();

        $this->assertCount(6, $res->json('todaySchedule.items'), 'البطاقة تعرض ستّاً');
        $this->assertSame(9, $res->json('todaySchedule.total'), 'والعدد يقول تسعاً');
    }

    // ── (ب) بلا analytics.view: الأقسام التحليلية null، والباقي يعمل ──

    public function test_user_without_analytics_permission_still_gets_200_and_permitted_kpis(): void
    {
        // مسؤول الجدولة: مرشحون + جدولة + حضور، بلا تحليلات ولا تقييمات ولا تقارير
        $this->actingAsRole('SCHEDULER');
        $ev = $this->evaluatorUser();
        $comp = Competency::orderBy('sort_order')->first();
        $this->fullCandidate('ED', $ev->id, $comp, 4);

        $res = $this->getJson('/api/dashboard/overview')->assertOk();

        // المحجوب بـ analytics.view
        $this->assertNull($res->json('readiness'));
        $this->assertNull($res->json('trend'));
        $this->assertNull($res->json('weekHeatmap'));
        $this->assertNull($res->json('insights'));

        // المسموح يبقى قائماً
        $this->assertNotNull($res->json('kpis.candidates'));
        $this->assertSame(1, $res->json('kpis.candidates.value'));
        $this->assertNotNull($res->json('kpis.completion'));
        $this->assertNotNull($res->json('kpis.attendance'));
        $this->assertNotNull($res->json('attendanceToday'));
        $this->assertNotNull($res->json('todaySchedule'));

        // المحجوب بصلاحياته الخاصة
        $this->assertNull($res->json('kpis.evaluations'));   // evaluation.view
        $this->assertNull($res->json('kpis.approvals'));     // report.view
        $this->assertNull($res->json('kpis.pending'));       // report.view

        // الغلاف حاضر دائماً ولو خلا من مؤشّر
        $this->assertIsArray($res->json('kpis'));
        $this->assertNotNull($res->json('generatedAt'));
    }

    // من لا يملك جدولة لا يرى جدول اليوم — ولا يُمنع من الصفحة
    public function test_user_without_schedule_permission_gets_null_today_schedule(): void
    {
        $this->actingAsRole('DEV_MANAGER');   // بلا schedule.view وبلا attendance.view
        $res = $this->getJson('/api/dashboard/overview')->assertOk();

        $this->assertNull($res->json('todaySchedule'));
        $this->assertNull($res->json('attendanceToday'));
        $this->assertNull($res->json('kpis.attendance'));
        $this->assertNotNull($res->json('readiness'));       // يملك analytics.view
        $this->assertNotNull($res->json('kpis.approvals'));  // يملك report.view
    }

    // ── (ج) الحصر بالقطاع: أرقام قطاعه وحده ──

    public function test_sector_bound_user_counts_only_their_own_sector(): void
    {
        $mine = $this->actingAsRole('EVALUATOR', 'ED');
        $this->assertTrue($mine->isSectorBound());

        $comp = Competency::orderBy('sort_order')->first();
        $other = $this->evaluatorUser('HI');

        // قطاعه: مرشّحان
        $this->fullCandidate('ED', $mine->id, $comp, 4);
        $this->fullCandidate('ED', $mine->id, $comp, 5);
        // قطاع آخر: ثلاثة — يجب ألا تُعدّ
        $this->fullCandidate('HI', $other->id, $comp, 3);
        $this->fullCandidate('HI', $other->id, $comp, 2);
        $this->fullCandidate('HI', $other->id, $comp, 5);

        $res = $this->getJson('/api/dashboard/overview')->assertOk();

        $this->assertSame(2, $res->json('kpis.candidates.value'));
        $this->assertSame(2, $res->json('kpis.evaluations.value'));
        $this->assertSame(2, $res->json('kpis.approvals.value'));
        // جلسات اليوم كذلك محصورة بقطاعه
        $this->assertSame(2, $res->json('attendanceToday.total'));
        $this->assertNull($res->json('todaySchedule'));   // المقيّم بلا schedule.view
        // التحليلات محجوبة عنه أصلاً (لا analytics.view) — لا تسرّب مقارنات قطاعات
        $this->assertNull($res->json('weekHeatmap'));
        $this->assertNull($res->json('insights'));
    }

    // التصنيف حدٌّ ثانٍ: من لا يرى المصنّفين لا يعدّهم
    public function test_classification_scope_excludes_classified_candidates(): void
    {
        $this->actingAsRole('SCHEDULER');   // بلا candidate.view_classified
        $this->makeCandidate(['sectorCode' => 'ED', 'classification' => 'normal']);
        $this->makeCandidate(['sectorCode' => 'ED', 'classification' => 'secret']);

        $res = $this->getJson('/api/dashboard/overview')->assertOk();
        $this->assertSame(1, $res->json('kpis.candidates.value'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/overview')->assertStatus(401);
    }
}
