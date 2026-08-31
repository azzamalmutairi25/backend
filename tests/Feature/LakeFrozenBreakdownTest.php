<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalReport;
use App\Services\ReportSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════════════════
//  لماذا تُجمَّد الحسبة ولا تُنسخ الصفوف
//
//  جسدُ التقرير غيرُ موجودٍ في القاعدة: final_reports يحفظ الترويسة فقط،
//  وتفصيلُ الكفاءات يُعاد حسابُه عند كل عرضٍ من evaluation_scores ×
//  competencies. و competencies.max_level و weight قابلتان للتحرير من
//  الشاشة — فالتقرير المعتمَد نفسُه يُنتج أرقاماً مختلفةً قبل التحرير
//  وبعده. ScoringService يشهد بذلك: يقصر النسبة على ١٠٠ تحسّباً لأن
//  يكون max_level قد خُفّض بعد الرصد.
//
//  فلو كانت البحيرة مرآةً للجداول لَتغيّر بها تقريرٌ اعتُمد قبل سنة.
//  هذا الاختبار يُحدث ذلك التحرير عمداً بين تجميدين، ويُثبت أن الأول
//  قيمةٌ لا تنجرف. هو مبرّرُ وجود ReportSnapshotService كلِّه.
// ════════════════════════════════════════════════════════════════════════
class LakeFrozenBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lake.pepper' => str_repeat('k', 32), 'lake.enabled' => true]);
    }

    /** كفاءةٌ واحدة مرصودة بدرجة ٤ من ٥ — تفصيلٌ يُقرأ بلا لبس */
    private function scenario(): array
    {
        $evaluator = $this->actingAsRole('EVALUATOR');
        [$c, $a] = $this->makeCandidate([
            'status' => 'assessed', 'assessmentStatus' => 'assessed', 'tier' => 'upper',
        ]);

        $comp = Competency::create([
            'name_ar' => 'كفاءة التجميد', 'type' => 'behavioral', 'group' => 'سلوكية',
            'max_level' => 5, 'weight' => 1, 'target_upper' => 4, 'target_middle' => 3,
            'sort_order' => 991,
        ]);

        $e = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $evaluator->id,
            'activity' => 'interview', 'status' => 'submitted', 'submitted_at' => now(),
        ]);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $comp->id, 'score' => 4]);

        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'approved', 'created_by' => null,
        ]);

        return [$comp, $report];
    }

    /** صفّ الكفاءة المعنيّة من تفصيلٍ مُجمَّد */
    private function row(array $payload, int $competencyId): array
    {
        foreach ($payload['breakdown'] ?? [] as $b) {
            if ($b['competency_id'] === $competencyId) {
                return $b;
            }
        }
        $this->fail('الكفاءة المرصودة غائبة عن التفصيل المُجمَّد');
    }

    public function test_a_frozen_breakdown_does_not_follow_later_edits(): void
    {
        [$comp, $report] = $this->scenario();
        $snapshots = app(ReportSnapshotService::class);

        $before = $snapshots->freeze($report, 'report.approved', 'approved');
        $rowBefore = $this->row($before, $comp->id);

        // ٤ من ٥ بوزن ١ ⇒ ٨٠٪، وهدف الشريحة العليا ٤ ⇒ لا فجوة
        $this->assertSame(5, $rowBefore['max_level']);
        $this->assertSame(1.0, (float) $rowBefore['weight']);
        $this->assertSame(80.0, (float) $rowBefore['pct']);
        $this->assertSame(4, $rowBefore['target_level']);
        $this->assertSame(0.0, (float) $rowBefore['gap']);
        $this->assertTrue($rowBefore['met']);

        // ── التحرير الذي يقع فعلاً في الشاشة ──
        // خفضُ السلّم إلى ٤ يجعل الدرجةَ نفسَها ١٠٠٪، ورفعُ الوزن يبدّل
        // نصيبَها من التوافق العام. هذا تحريرُ إطارٍ مشروع، والخطأ الوحيد
        // أن يُعيد كتابةَ تقريرٍ اعتُمد قبله.
        $comp->update(['max_level' => 4, 'weight' => 3, 'target_upper' => 2]);

        // ١) الظرف الأول قيمةٌ في يد المستدعي — يُقرأ بعد التحرير كما كان
        $frozen = $this->row($before, $comp->id);
        $this->assertSame(5, $frozen['max_level']);
        $this->assertSame(1.0, (float) $frozen['weight']);
        $this->assertSame(80.0, (float) $frozen['pct']);
        $this->assertSame(4, $frozen['target_level']);
        $this->assertSame(0.0, (float) $frozen['gap']);

        // ٢) تجميدٌ جديد يعكس الإطار الجديد — وهو الصواب: هو حدثٌ آخر
        $after = $snapshots->freeze($report->fresh(), 'report.approved', 'approved');
        $rowAfter = $this->row($after, $comp->id);

        $this->assertSame(4, $rowAfter['max_level']);
        $this->assertSame(3.0, (float) $rowAfter['weight']);
        $this->assertSame(100.0, (float) $rowAfter['pct']);
        $this->assertSame(2, $rowAfter['target_level']);
        $this->assertSame(2.0, (float) $rowAfter['gap']);
    }

    public function test_the_overall_fit_frozen_with_it_does_not_move_either(): void
    {
        [$comp, $report] = $this->scenario();
        $snapshots = app(ReportSnapshotService::class);

        $before = $snapshots->freeze($report, 'report.approved', 'approved');
        $this->assertSame(80.0, (float) $before['report']['overall_fit']);

        $comp->update(['max_level' => 4]);

        $this->assertSame(80.0, (float) $before['report']['overall_fit']);
        $after = $snapshots->freeze($report->fresh(), 'report.approved', 'approved');
        $this->assertSame(100.0, (float) $after['report']['overall_fit']);
    }

    // بصمةُ الإطار هي ما يجعل الفرق مفهوماً لا مجرّد اختلافٍ في رقمين:
    // بدونها يقرأ المحلّل رقمين متعارضين لتقريرٍ واحد بلا ما يفسّرهما.
    public function test_the_dimension_fingerprint_records_which_framework_computed_the_numbers(): void
    {
        [$comp, $report] = $this->scenario();
        $snapshots = app(ReportSnapshotService::class);

        $before = $snapshots->freeze($report, 'report.approved', 'approved');
        $comp->update(['max_level' => 4]);
        $after = $snapshots->freeze($report->fresh(), 'report.approved', 'approved');

        $this->assertNotSame(
            $before['dimensions']['competency_version'],
            $after['dimensions']['competency_version']);
    }

    // الظرف المكتوب في صندوق الصادر هو النصّ لا المرجع: تحريرٌ لاحق
    // لا يمسّ ما شُحن. الفحص على العمود نفسه لأنه ما يصل البحيرة فعلاً.
    public function test_the_shipped_payload_column_is_text_not_a_live_view(): void
    {
        [$comp, $report] = $this->scenario();
        app(\App\Support\LakeEmitter::class)->report($report, 'report.approved', 'approved');

        $payload = json_decode(
            \Illuminate\Support\Facades\DB::table('report_lake_outbox')->value('payload'), true);
        $this->assertSame(5, $this->row($payload, $comp->id)['max_level']);

        $comp->update(['max_level' => 4]);

        $reread = json_decode(
            \Illuminate\Support\Facades\DB::table('report_lake_outbox')->value('payload'), true);
        $this->assertSame(5, $this->row($reread, $comp->id)['max_level']);
    }
}
