<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Services\ReportSnapshotService;
use App\Support\LakeEmitter;
use App\Support\LakeSuppressed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════════════════
//  بوّابة التصنيف — المُصنَّف لا يغادر القاعدة الأساسية
//
//  المنصّة تُنكر وجود الصفّ المُصنَّف أصلاً (٤٠٤ لا ٤٠٣، انظر
//  CandidateSecurityTest). تصديرُه إلى قاعدةٍ يقرؤها طرفٌ ثالث نقضٌ
//  للضابط نفسه: من مُنع من معرفة وجوده يقرأ صفّه هناك.
//
//  والمنع يقع قبل بناء الحمولة لا بعده — حمولةٌ تُبنى ثم تُرمى تبقى
//  لحظةً في الذاكرة وفي أثر أيّ استثناءٍ يُسجَّل بينهما.
//
//  ويُميَّز الحجب عن العطل بنوعٍ خاصّ (LakeSuppressed): الأول متوقَّعٌ
//  بالتصميم فيُمرَّر بصمت، والثاني يُسجَّل ويُنبَّه عليه. لولا الفصل
//  لَابتلع مسارُ الخطأ أحدَهما.
// ════════════════════════════════════════════════════════════════════════
class LakeClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lake.pepper' => str_repeat('k', 32), 'lake.enabled' => true]);
    }

    private function reportFor(string $classification): FinalReport
    {
        [$c, $a] = $this->makeCandidate([
            'status' => 'assessed', 'assessmentStatus' => 'assessed',
            'classification' => $classification,
        ]);

        return FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'approved', 'created_by' => null,
        ]);
    }

    public function test_freeze_suppresses_a_secret_candidate(): void
    {
        $report = $this->reportFor('secret');

        $this->expectException(LakeSuppressed::class);
        app(ReportSnapshotService::class)->freeze($report, 'report.approved', 'approved');
    }

    public function test_freeze_suppresses_a_top_secret_candidate(): void
    {
        $report = $this->reportFor('top_secret');

        $this->expectException(LakeSuppressed::class);
        app(ReportSnapshotService::class)->freeze($report, 'report.approved', 'approved');
    }

    public function test_freeze_succeeds_for_a_normal_candidate(): void
    {
        $report = $this->reportFor('normal');

        $payload = app(ReportSnapshotService::class)->freeze($report, 'report.approved', 'approved');

        $this->assertSame('report.approved', $payload['event_type']);
        $this->assertSame('approved', $payload['report']['status']);
    }

    // التقرير بلا مشارك حجبٌ لا عطل: الظرف كلُّه يقوم على المشارك،
    // فبناؤه بدونه كان سيُنتج صفّاً بلا موضوع.
    //
    // الحالة لا تُصنع بحذف المشارك: القيد يحذف تقريرَه معه (cascade)،
    // فلا يبقى تقريرٌ يُجمَّد. تُصنع بنموذجٍ قديمٍ في الذاكرة — وهو ما يقع
    // فعلاً في التعبئة التاريخية حين تُقرأ التقارير دفعةً ثم تُنقّى
    // القاعدة أثناء المرور عليها. الحارس دفاعيّ، ويبقى مُثبَّتاً.
    public function test_freeze_suppresses_a_report_without_a_candidate(): void
    {
        $report = $this->reportFor('normal');
        $report->setRelation('candidate', null);

        $this->expectException(LakeSuppressed::class);
        app(ReportSnapshotService::class)->freeze($report, 'report.approved', 'approved');
    }

    public function test_emitter_writes_nothing_for_a_classified_candidate(): void
    {
        $emitter = app(LakeEmitter::class);

        // الحجب يُرجع false كما يفعل «مُعطَّل» — الاثنان يعنيان «لا صفّ»،
        // والتمييز بينهما شأنُ المطابقة الليلية لا شأنُ نداء الطلب.
        $this->assertFalse($emitter->report($this->reportFor('secret'), 'report.approved', 'approved'));
        $this->assertFalse($emitter->report($this->reportFor('top_secret'), 'report.approved', 'approved'));

        $this->assertSame(0, DB::table('report_lake_outbox')->count());
    }

    public function test_emitter_writes_exactly_one_row_for_a_normal_candidate(): void
    {
        $report = $this->reportFor('normal');

        $this->assertTrue(app(LakeEmitter::class)->report($report, 'report.approved', 'approved'));

        $rows = DB::table('report_lake_outbox')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('normal', $rows[0]->classification);
        $this->assertSame($report->id, (int) $rows[0]->source_report_id);
        $this->assertFalse((bool) $rows[0]->degraded);
    }

    // العمود في صندوق الصادر شاهدٌ ثانٍ لا مصدرُ قرار: القرار وقع في
    // freeze قبل أن يُكتب صفّ، ووجودُ صفٍّ مُصنَّفٍ هنا يعني أن البوّابة
    // الأولى سقطت — فيبقى الفحص عليه أيضاً.
    public function test_no_classified_row_ever_reaches_the_outbox(): void
    {
        $emitter = app(LakeEmitter::class);
        foreach (['normal', 'secret', 'top_secret', 'normal'] as $cls) {
            $emitter->report($this->reportFor($cls), 'report.approved', 'approved');
        }

        $this->assertSame(2, DB::table('report_lake_outbox')->count());
        $this->assertSame(0, DB::table('report_lake_outbox')
            ->whereNotIn('classification', (array) config('lake.classifications'))->count());
    }
}
