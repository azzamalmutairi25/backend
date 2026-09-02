<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Services\ReportSnapshotService;
use App\Support\LakeEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════════════════
//  الشرط الذي لا يُقايَض: لا تغادر هويةٌ القاعدةَ الأساسية
//
//  بقيّة اختبارات البحيرة تصف سلوكاً يمكن مراجعتُه لاحقاً. هذا الاختبار
//  يصف قراراً أمنياً: البحيرة «مجهولة الهوية بالكامل — معرّف بديل فقط».
//  سقوطُه ليس انحداراً في ميزة، بل انكشافُ بياناتٍ شخصية في قاعدةٍ
//  يقرؤها طرفٌ ثالث، فلا يُعالَج بتعديل التوقّع.
//
//  الفحص بالمسح النصّي لا بقراءة المفاتيح عمداً: مفتاحٌ جديد يُضاف يوماً
//  إلى الظرف لا يعرفه هذا الملف، والمسحُ يلتقطه. قراءةُ المفاتيح المعروفة
//  كانت ستمرّ على التسريب الذي لم يُتوقَّع — وهو وحده الذي يقع فعلاً.
// ════════════════════════════════════════════════════════════════════════
class LakePiiInvariantsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // بياناتٌ مميّزة لا تشبه شيئاً في الظرف: لو ظهر أيٌّ منها في البحيرة
    // فهو تسريبٌ حقيقي لا مصادفةً نصّية.
    private const NAME = 'زياد بن مطلق الفريدي';

    private const NID = '1099887766';

    private const MOBILE = '0555000111';

    private const EMAIL = 'ziyad.unique@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        // فلفلٌ ثابت داخل الاختبار: LAKE_PEPPER لا يُضبط في .env.testing
        // (ولا يجوز أن يُضبط — سرٌّ تشغيليّ لا يُودَع في المستودع).
        config(['lake.pepper' => str_repeat('k', 32), 'lake.enabled' => true]);
    }

    private function subject(): array
    {
        // معرّفٌ مرتفعٌ ومميّز. الفحص «لا يظهر معرّف المشارك الخام» بلا معنى
        // لو كان المعرّف ١: الرقم ١ يرد في كل ظرفٍ وزناً أو ترتيباً أو عدّاً.
        // برقمٍ من ستّ خانات يصير وجودُه في النصّ دليلاً لا احتمالاً.
        DB::statement('ALTER SEQUENCE candidates_id_seq RESTART WITH 987654');

        [$c, $a] = $this->makeCandidate([
            'status' => 'assessed', 'assessmentStatus' => 'assessed',
            'fullName' => self::NAME, 'nationalId' => self::NID, 'mobile' => self::MOBILE,
        ]);
        $c->email = self::EMAIL;
        $c->save();

        // لو فشل ضبط المتتالية لَصار المعرّف ١ ولَمرّ فحصُ «لا يظهر معرّف
        // المشارك» بلا معنى. الفحص هنا يمنع أن يتحوّل الاختبار إلى طقس.
        $this->assertSame(987654, $c->id, 'المعرّف المميّز لم يُضبط — الفحص يصير بلا دلالة');

        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به مع خطة تطوير', 'status' => 'approved', 'created_by' => null,
        ]);

        return [$c, $report];
    }

    /** كل ما يجب ألّا يُوجد، ولماذا لكلٍّ منه */
    private function assertCarriesNoIdentity(string $haystack, $candidate, string $where): void
    {
        $forbidden = [
            self::NAME => 'الاسم الكامل',
            self::NID => 'رقم الهوية الخام',
            // تجزئةٌ بلا ملح على فضاء هويةٍ من عشر خانات — تُعكس بالبحث
            // الشامل في ثوانٍ، فنشرُها يساوي نشر الرقم نفسه.
            $candidate->national_id_hash => 'تجزئة رقم الهوية',
            self::MOBILE => 'الجوال',
            self::EMAIL => 'البريد',
            (string) $candidate->id => 'معرّف المشارك الداخلي',
            'national_id' => 'اسم حقل الهوية',
            'full_name' => 'اسم حقل الاسم',
            // ظهورُه يعني أن عموداً مشفّراً نُسخ كما هو — والتشفير لا يقي
            // من نقل النصّ المشفّر نفسه إلى قاعدةٍ لا تملك APP_KEY فحسب،
            // بل يعني أن المسار ينسخ الأعمدة بدل أن يبني ظرفاً.
            '_enc' => 'عمود مشفّر منقول كما هو',
        ];

        foreach ($forbidden as $needle => $why) {
            $this->assertStringNotContainsString(
                (string) $needle, $haystack, "{$where}: تسرّب {$why}");
        }
    }

    public function test_frozen_payload_carries_no_identity(): void
    {
        [$c, $report] = $this->subject();

        $payload = app(ReportSnapshotService::class)->freeze($report, 'report.approved', 'approved');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // الظرف ليس فارغاً — وإلا مرّ الفحص لأن لا شيء فيه أصلاً
        $this->assertArrayHasKey('subject', $payload);
        $this->assertSame($c->sector_id, $payload['subject']['sector_id']);

        $this->assertCarriesNoIdentity($json, $c, 'الظرف المُجمَّد');
    }

    public function test_outbox_row_in_full_carries_no_identity(): void
    {
        [$c, $report] = $this->subject();

        $this->assertTrue(app(LakeEmitter::class)->report($report, 'report.approved', 'approved'));

        // الصفّ كاملاً لا حمولتَه فقط: الأعمدة الجانبية (person_ref،
        // participant_code، classification) هي أيضاً ممّا يُشحن.
        $row = (array) DB::table('report_lake_outbox')->first();
        $this->assertCarriesNoIdentity(
            json_encode($row, JSON_UNESCAPED_UNICODE), $c, 'صفّ صندوق الصادر');
    }

    public function test_person_ref_is_a_surrogate_not_a_hash_of_the_national_id(): void
    {
        [$c, $report] = $this->subject();
        app(LakeEmitter::class)->report($report, 'report.approved', 'approved');

        $ref = DB::table('report_lake_outbox')->value('person_ref');

        $this->assertNotNull($ref, 'بلا معرّف بديل لا يُربط حدثان لشخصٍ واحد');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ref);

        // الفرق الجوهري: HMAC بفلفلٍ لا يغادر خادم التطبيق، لا SHA-256
        // مكشوفة يُعاد حسابُها من رقم هويةٍ مُخمَّن.
        $this->assertNotSame(hash('sha256', self::NID), $ref);
        $this->assertNotSame($c->national_id_hash, $ref);
        $this->assertNotSame(hash('sha256', (string) $c->id), $ref);
    }

    public function test_participant_code_is_withheld_by_default(): void
    {
        // ليس اسماً، لكنه يكشف القطاع وترتيب الالتحاق ويربط صفّ البحيرة
        // بشاشة المنصّة مباشرةً — فهو مُعطّل حتى يُفتح بقرار.
        [$c, $report] = $this->subject();
        app(LakeEmitter::class)->report($report, 'report.approved', 'approved');

        $this->assertFalse((bool) config('lake.publish.participant_code'));
        $this->assertNull(DB::table('report_lake_outbox')->value('participant_code'));
    }

    // فحصُ الفحص. المسح النصّي يمرّ حين لا يجد شيئاً — وهو أيضاً ما يفعله
    // حين يبحث في نصٍّ فارغ أو عن سلاسل خاطئة. فيُزرع هنا تسريبٌ متعمَّد
    // ويُشترط أن يسقط عليه، وإلا كان الاطمئنان في بقية الملف بلا سند.
    public function test_the_scan_itself_catches_a_planted_leak(): void
    {
        [$c] = $this->subject();

        foreach ([self::NAME, self::NID, self::MOBILE, self::EMAIL,
            (string) $c->id, $c->national_id_hash, 'full_name_enc'] as $planted) {
            try {
                $this->assertCarriesNoIdentity(
                    '{"x":"'.$planted.'"}', $c, 'نصّ مزروع');
                $this->fail("المسح لم يلتقط تسريباً مزروعاً: {$planted}");
            } catch (AssertionFailedError $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_narrative_is_withheld_by_default(): void
    {
        [$c, $report] = $this->subject();
        $report->update([
            'overview_text' => 'نظرةٌ عامة تذكر '.self::NAME,
            'executive_summary' => 'ملخّصٌ تنفيذي',
            'strengths' => ['قيادة'],
        ]);

        $payload = app(ReportSnapshotService::class)->freeze($report, 'report.approved', 'approved');

        $this->assertFalse((bool) config('lake.publish.narrative'));
        $this->assertArrayNotHasKey('narrative', $payload);
        $this->assertStringNotContainsString(
            self::NAME, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
