<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Support\LakeEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════════════════
//  «لا يكلّف شيئاً وهو مُطفأ» — دعوى تُختبر لا تُقال
//
//  البحيرة أُضيفت إلى منصّةٍ في الإنتاج. الشرط الذي قُبلت به: ما لم
//  يُفعَّل LAKE_ENABLED صراحةً فلا شيء يحدث — لا صفَّ يُكتب، ولا حمولةَ
//  تُبنى، ولا استعلامَ يُضاف إلى مسار اعتماد تقرير.
//
//  ولذلك يُفحص المفتاح أوّلَ سطرٍ في report()، قبل قراءة المشارك: فحصٌ
//  متأخّرٌ كان سيدفع كلفةَ البناء ثم يرمي نتيجتَها.
// ════════════════════════════════════════════════════════════════════════
class LakeEmitterDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lake.pepper' => str_repeat('k', 32)]);
    }

    private function report(): FinalReport
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);

        return FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'approved', 'created_by' => null,
        ]);
    }

    public function test_disabled_is_the_shipped_default(): void
    {
        // الافتراض في config/lake.php هو false. لو انقلب يوماً لَصار
        // نشرُ إصدارٍ جديد كافياً لبدء التصدير بلا قرار.
        $this->assertFalse((bool) config('lake.enabled'));
    }

    public function test_disabled_emitter_writes_nothing_and_returns_false(): void
    {
        config(['lake.enabled' => false]);
        $report = $this->report();

        $this->assertFalse(app(LakeEmitter::class)->enabled());
        $this->assertFalse(app(LakeEmitter::class)->report($report, 'report.approved', 'approved'));
        $this->assertSame(0, DB::table('report_lake_outbox')->count());
    }

    // اللقطة اليومية تمرّ بالبوّابة نفسها — واجهةٌ ثانية بلا حارس كانت
    // ستُبقي الجدولَ يمتلئ والمفتاحُ مُطفأ.
    public function test_disabled_snapshot_writes_nothing_and_returns_false(): void
    {
        config(['lake.enabled' => false]);

        $this->assertFalse(app(LakeEmitter::class)->snapshot('daily.snapshot', 'day:2026-08-20', ['x' => 1]));
        $this->assertSame(0, DB::table('report_lake_outbox')->count());
    }

    public function test_enabled_emitter_writes_exactly_one_row(): void
    {
        config(['lake.enabled' => true]);
        $report = $this->report();

        $this->assertTrue(app(LakeEmitter::class)->enabled());
        $this->assertTrue(app(LakeEmitter::class)->report($report, 'report.approved', 'approved'));
        $this->assertSame(1, DB::table('report_lake_outbox')->count());
    }

    // الإطفاء لا يمسّ ما كُتب قبله: صفوفٌ سابقة تبقى للشحن حين يُعاد
    // التشغيل. المفتاح يمنع الإنتاج لا يمسح المخزون.
    public function test_turning_it_off_does_not_erase_what_was_already_queued(): void
    {
        config(['lake.enabled' => true]);
        app(LakeEmitter::class)->report($this->report(), 'report.approved', 'approved');

        config(['lake.enabled' => false]);
        app(LakeEmitter::class)->report($this->report(), 'report.approved', 'approved');

        $this->assertSame(1, DB::table('report_lake_outbox')->count());
        // العدّاد يقرأ الجدول مباشرةً فلا يتأثّر بالمفتاح — وهو المقصود:
        // التراكم يُقاس ليُعرف أن الشحن متوقّف.
        $this->assertSame(1, app(LakeEmitter::class)->backlog());
    }
}
