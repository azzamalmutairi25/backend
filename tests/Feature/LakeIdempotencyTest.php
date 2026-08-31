<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Support\LakeEmitter;
use App\Support\LakeRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════════════════
//  إعادةُ الإرسال بلا ضرر
//
//  البحيرة تُغذّى عبر صندوق صادرٍ يُشحن لاحقاً، والشحن بين قاعدتين لا
//  يمكن أن يكون معاملةً واحدة. الترتيب المختار (تُثبَّت البحيرة أوّلاً ثم
//  تُعلَّم المنصّة) يعني أن انقطاعاً بين الخطوتين يُعيد شحنَ ما شُحن —
//  وهذا مقبولٌ فقط لأن المعرّف اشتقاقيّ: المفتاح نفسه يُنتج المعرّف نفسه
//  فيُمتصّ. لو كان عشوائياً لَتضاعف كلُّ حدثٍ عند أول تعثّر، بصمت.
//
//  والزمن يُختم مرّةً واحدة عند الإصدار ولا يُعاد ختمُه: البحيرة مُجزّأة
//  بـ occurred_at ومفتاحُها الفريد يتضمّنه، فإعادةُ ختمِه كانت تُهبط
//  المحاولةَ الثانية في قسمٍ آخر — أي صفّان لحدثٍ واحد.
// ════════════════════════════════════════════════════════════════════════
class LakeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lake.pepper' => str_repeat('k', 32), 'lake.enabled' => true]);
    }

    private function report(): FinalReport
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);

        return FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'approved', 'created_by' => null,
        ]);
    }

    public function test_event_uuid_is_deterministic_for_the_same_key(): void
    {
        $key = 'report.approved:42:approved:2026-08-20T10:00:00.000000';

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(LakeRef::eventUuid($key), LakeRef::eventUuid($key));
        }

        // متّجهٌ مثبَّت: الاشتقاق عقدٌ مع ما شُحن سابقاً لا تفصيلُ تنفيذ.
        // تغييرُ الخوارزمية أو فضاء الأسماء يجعل كلَّ حدثٍ قديمٍ يُعاد
        // إرسالُه صفّاً جديداً — فيُكسر هذا السطر قبل أن تُكسر البحيرة.
        $this->assertSame('646f9755-597a-5f47-9151-a08763f5fea9', LakeRef::eventUuid($key));
    }

    public function test_event_uuid_differs_for_a_different_key(): void
    {
        $this->assertNotSame(
            LakeRef::eventUuid('report.approved:42:approved:t'),
            LakeRef::eventUuid('report.approved:43:approved:t'));

        // حتى فرقُ محرفٍ واحد يكفي — وإلا انهار حدثان مختلفان في صفّ واحد
        $this->assertNotSame(LakeRef::eventUuid('a'), LakeRef::eventUuid('b'));
    }

    public function test_event_uuid_is_a_well_formed_v5_uuid(): void
    {
        // إصدار ٥ ومتغيّر RFC 4122 — العمود في البحيرة من نوع uuid،
        // فبِتّاتٌ غير مضبوطة كانت ستُرفض عند الإدراج لا عند التوليد.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            LakeRef::eventUuid('report.approved:1:approved:t'));
    }

    // تغييرُ فضاء الأسماء يقطع الاستمرارية مع كل ما سبق — يُوثَّق أثرُه
    // هنا حتى لا يُبدَّل الإعدادُ على أنه تفصيلٌ لا يُرى.
    public function test_event_uuid_follows_the_configured_namespace(): void
    {
        $a = LakeRef::eventUuid('same-key');
        config(['lake.uuid_namespace' => '11111111-2222-3333-4444-555555555555']);
        $b = LakeRef::eventUuid('same-key');

        $this->assertNotSame($a, $b);
    }

    public function test_the_same_logical_event_lands_once(): void
    {
        $report = $this->report();
        $emitter = app(LakeEmitter::class);

        // ctx['key'] هو ما يجعل الحدث «منطقياً»: بدونه يدخل الوقتُ في
        // المفتاح فتصير كلُّ محاولةٍ حدثاً جديداً بالتعريف — وهو الصواب
        // للانتقال الحيّ، والخطأ لإعادة التعبئة التاريخية.
        $ctx = ['key' => 'backfill:report:' . $report->id];

        $this->assertTrue($emitter->report($report, 'report.approved', 'approved', $ctx));
        $this->assertTrue($emitter->report($report, 'report.approved', 'approved', $ctx));

        $this->assertSame(1, DB::table('report_lake_outbox')->count());
    }

    public function test_occurred_at_is_not_restamped_on_the_second_attempt(): void
    {
        $report = $this->report();
        $emitter = app(LakeEmitter::class);
        $ctx = ['key' => 'backfill:report:' . $report->id];

        $this->travelTo('2026-08-20 10:00:00');
        $emitter->report($report, 'report.approved', 'approved', $ctx);
        $first = DB::table('report_lake_outbox')->value('occurred_at');

        // ساعةٌ كاملة لاحقاً: لو أُعيد الختم لَسقط الصفّ في قسمٍ آخر عند
        // الشحن، ولَبدا الحدثُ وقد وقع مرّتين في تحليلٍ زمني.
        $this->travelTo('2026-08-20 11:30:00');
        $emitter->report($report, 'report.approved', 'approved', $ctx);
        $this->travelBack();

        $rows = DB::table('report_lake_outbox')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($first, $rows[0]->occurred_at);
        $this->assertStringContainsString('10:00:00', (string) $rows[0]->occurred_at);
    }

    // الحمولة أيضاً هي حمولةُ المحاولة الأولى: insertOrIgnore يتجاهل
    // الصفّ الثاني كاملاً، فالتقرير الذي حُرِّر بين المحاولتين لا يُبدّل
    // ما جُمّد. هذا مقصود — الظرف يصف لحظةَ الحدث لا لحظةَ الشحن.
    public function test_the_second_attempt_does_not_overwrite_the_frozen_payload(): void
    {
        $report = $this->report();
        $emitter = app(LakeEmitter::class);
        $ctx = ['key' => 'backfill:report:' . $report->id];

        $emitter->report($report, 'report.approved', 'approved', $ctx);
        $before = DB::table('report_lake_outbox')->value('payload_sha256');

        $report->update(['recommendation' => 'توصيةٌ مُحرَّرة بعد الاعتماد']);
        $emitter->report($report->fresh(), 'report.approved', 'approved', $ctx);

        $this->assertSame(1, DB::table('report_lake_outbox')->count());
        $this->assertSame($before, DB::table('report_lake_outbox')->value('payload_sha256'));
    }
}
