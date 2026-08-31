<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\CandidateCv;
use App\Models\ImportBatch;
use App\Services\CvGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  ثلاثة أعطالٍ كانت بلا تغطية، وكلٌّ منها يُفقد بياناتٍ بصمت.
//
//  ١) زيادات نموذج الوزارة السبع: يقرؤها المستورِد ويحفظها الخادم، وكان
//     محرّر السيرة يُعيد بناء الوثيقة من قائمةٍ بيضاء لا تضمّها — والخادم
//     يستبدل لا يدمج. فأوّل ضغطة «حفظ» تمحوها كلَّها بلا رسالة.
//  ٢) `import_batches.payload` كان `json` عارياً يحمل الأسماء والهويات
//     والجوالات، بينما جدول المشاركين يشفّرها.
//  ٣) الرقم العسكري مُعرِّفٌ مباشر، والشبكة العامّة في CvGuard لا تلتقط إلا
//     تسعة أرقام فأكثر — وهو أقصر من ذلك، فكان يبلغ المقيّم بلا طمس.
// ════════════════════════════════════════════════════════════
class OptionalFieldsAndIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // حمولةٌ كاملة صالحة — `candidateRequired()` في القاعدة تعطي الزوائد وحدها
    private function payload(array $over = []): array
    {
        return array_replace([
            'nationalId' => $this->validNationalId(),
            'fullName' => 'مشارك اختبار',
            'sectorId' => \App\Models\Sector::first()->id,
            'rankLabel' => 'الرابعة عشرة',
            'personnelCategory' => 'civilian',
        ] + $this->candidateRequired(), $over);
    }

    // المفاتيح السبعة التي كانت تسقط بين المستورِد والمحرّر
    private const EXTRAS = [
        'rankTitle' => 'ركن',
        'rankPromotedAt' => '2022-01-15',
        'generalDepartment' => 'الإدارة العامة للعمليات',
        'workCity' => 'الرياض',
        'currentPositionYears' => '٣ سنوات',
    ];

    public function test_the_moi_extra_job_fields_survive_a_save(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->postJson('/api/candidates', $this->payload([
            'cv' => $this->validCvDoc(self::EXTRAS + [
                'experiences' => [[
                    'position' => 'رئيس قسم', 'organization' => 'الإدارة العامة للتخطيط',
                    'section' => 'قسم الدراسات', 'years' => '٤ سنوات',
                ]],
            ]),
        ]))->assertStatus(201);

        $doc = Candidate::find($res->json('candidateId'))->cv->data;

        foreach (self::EXTRAS as $key => $value) {
            $this->assertSame($value, $doc[$key], "المفتاح {$key} سقط بين الحمولة والقاعدة");
        }
        $this->assertSame('قسم الدراسات', $doc['experiences'][0]['section']);
        $this->assertSame('٤ سنوات', $doc['experiences'][0]['years']);
    }

    // الفخّ الحقيقي: إعادة حفظ الوثيقة كما قُرئت يجب ألّا تُنقصها شيئاً
    public function test_re_saving_a_cv_unchanged_does_not_drop_the_extras(): void
    {
        $this->actingAsRole('SCHEDULER');
        $res = $this->postJson('/api/candidates', $this->payload([
            'cv' => $this->validCvDoc(self::EXTRAS),
        ]))->assertStatus(201);

        $id = $res->json('candidateId');
        $cv = Candidate::find($id)->cv;

        // ما يُرسله المحرّر بعد فتحه وحفظه بلا تغيير
        $this->putJson("/api/candidates/{$id}/cv", [
            'cv' => $cv->data,
            'expectedVersion' => $cv->version,
        ])->assertOk();

        $after = Candidate::find($id)->cv->fresh()->data;
        foreach (self::EXTRAS as $key => $value) {
            $this->assertSame($value, $after[$key], "المفتاح {$key} مُحي عند إعادة الحفظ");
        }
    }

    public function test_import_batch_payload_is_encrypted_at_rest(): void
    {
        $batch = ImportBatch::create([
            'user_id' => $this->actingAsRole('SCHEDULER')->id,
            'status' => 'queued',
            'payload' => [['nationalId' => '1054321987', 'fullName' => 'محمد الشهري']],
        ]);

        $raw = (string) DB::table('import_batches')->where('id', $batch->id)->value('payload');

        $this->assertStringNotContainsString('1054321987', $raw, 'رقم الهوية مكتوبٌ نصّاً في حمولة الدفعة');
        $this->assertStringNotContainsString('محمد الشهري', $raw, 'الاسم مكتوبٌ نصّاً في حمولة الدفعة');
        // ويُقرأ سليماً رغم التشفير
        $this->assertSame('1054321987', $batch->fresh()->payload[0]['nationalId']);
    }

    public function test_the_military_number_is_a_direct_identifier(): void
    {
        $this->actingAsRole('SCHEDULER');
        $res = $this->postJson('/api/candidates', $this->payload([
            'militaryNumber' => '480321',
        ]))->assertStatus(201);

        $candidate = Candidate::find($res->json('candidateId'));

        // يُرفض حفظُ سيرةٍ تحمله في نصٍّ حرّ
        $hit = CvGuard::directIdentifierHit(
            $this->validCvDoc(['briefBio' => 'الرقم الوظيفي 480321 لدى الجهة']),
            $candidate
        );
        $this->assertNotNull($hit, 'الرقم العسكري مرّ في نصّ السيرة بلا اعتراض');

        // ويُطمَس عند العرض للمقيّم — الشبكة العامّة تبدأ من تسعة أرقام فلا تلتقطه
        $scrubbed = CvGuard::scrub(
            $this->validCvDoc(['briefBio' => 'خدم برقم 480321 عشر سنوات']),
            $candidate
        );
        $this->assertStringNotContainsString('480321', $scrubbed['briefBio']);
    }

    // ولا يُطمَس ما ليس معرّفاً: رقمٌ آخر في النصّ يبقى كما هو
    public function test_scrubbing_leaves_unrelated_numbers_alone(): void
    {
        $this->actingAsRole('SCHEDULER');
        $res = $this->postJson('/api/candidates', $this->payload([
            'militaryNumber' => '480321',
        ]))->assertStatus(201);

        $scrubbed = CvGuard::scrub(
            $this->validCvDoc(['briefBio' => 'تخرّج سنة 2005 وقاد فريقاً من 12 فرداً']),
            Candidate::find($res->json('candidateId'))
        );

        $this->assertStringContainsString('2005', $scrubbed['briefBio']);
        $this->assertStringContainsString('12', $scrubbed['briefBio']);
    }

    // ── الأربعة الإلزامية وحدها ──
    public function test_only_the_four_structural_fields_are_required(): void
    {
        $this->actingAsRole('SCHEDULER');

        // بلا جنس ولا فئة ولا طبقة ولا مجالات ولا سيرة — يُحفَظ
        $res = $this->postJson('/api/candidates', [
            'nationalId' => $this->validNationalId(),
            'fullName' => 'الحدّ الأدنى',
            'sectorId' => \App\Models\Sector::first()->id,
            'rankLabel' => 'الرابعة عشرة',
        ])->assertStatus(201);

        $c = Candidate::find($res->json('candidateId'));
        $this->assertNull($c->gender);
        $this->assertSame(0, CandidateCv::count());
        $this->assertSame(config('participants.defaults.personnelCategory'), $c->personnel_category);

        // وكلٌّ من الأربعة يُردّ بغيابه
        foreach (['nationalId', 'fullName', 'sectorId', 'rankLabel'] as $field) {
            $payload = [
                'nationalId' => $this->validNationalId(), 'fullName' => 'س',
                'sectorId' => \App\Models\Sector::first()->id, 'rankLabel' => 'الرابعة عشرة',
            ];
            unset($payload[$field]);
            $this->postJson('/api/candidates', $payload)
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }
}
