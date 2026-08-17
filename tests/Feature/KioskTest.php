<?php

namespace Tests\Feature;

use App\Models\ReceptionKiosk;
use App\Models\ReceptionVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  كشك الاستقبال — مسارٌ بلا مصادقة على جهازٍ في مكان عام.
//
//  ما يُثبَّت هنا ليس «هل تعمل الشاشة» بل «ماذا يمنع الكشك». المسار مفتوح
//  بلا جلسة موظّف، فكل حارسٍ فيه (نطاق اليوم، الإبطال، بوّابة الهوية، حدّ
//  المحاولات، ترتيب الوصول ← التوقيع ← البطاقة) هو ما يفصل بين كشكٍ يخدم
//  الطابور وجهازٍ يسرّب بيانات المرشحين لمن يمسكه.
// ════════════════════════════════════════════════════════════
class KioskTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';

    protected function setUp(): void
    {
        parent::setUp();
        // العدّادات مشتركة بين الاختبارات في مخزنٍ واحد — بقاؤها يقفل كشكاً
        // في اختبارٍ لم يُخطئ فيه أحد
        RateLimiter::clear('kiosk:1');
    }

    // كشكٌ جاهز في القاعدة — مسارات الكشك لا تمرّ بجلسة، فلا حاجة لمصادقة
    // هنا. المُصدِر مسؤول استقبال كما في الواقع.
    private function kiosk(array $attrs = []): ReceptionKiosk
    {
        $officer = $this->officer ??= $this->actingAsRole('RECEPTIONIST');

        return ReceptionKiosk::create(array_merge([
            'token' => ReceptionKiosk::generateToken(),
            'kiosk_date' => now()->toDateString(),
            'created_by' => $officer->id,
        ], $attrs));
    }

    private $officer = null;

    // ── بوّابة الهوية ──

    public function test_kiosk_reveals_nothing_before_the_national_id_matches(): void
    {
        [$c] = $this->makeCandidate();
        $k = $this->kiosk();

        // حالة الكشك قبل أي هوية: جاهزيةٌ فقط، ولا اسم ولا رمز مشارك
        $state = $this->getJson("/api/kiosk/{$k->token}")->assertOk();
        $state->assertJsonPath('ready', true);
        $state->assertJsonMissingPath('candidate');

        // هوية لا تخصّ أحداً: لا بيان، ونفس الردّ الذي يُعطى لغير المتوقَّع
        $this->postJson("/api/kiosk/{$k->token}/identify", ['nationalId' => '1000000000'])
            ->assertStatus(404)
            ->assertJsonMissingPath('candidate');

        // الاسم الحقيقي لم يظهر في أي ردّ حتى الآن
        $this->assertNotEmpty($c->full_name);
    }

    public function test_matching_national_id_returns_the_candidate_and_records_arrival(): void
    {
        [$c, $a] = $this->makeCandidate();
        $k = $this->kiosk();

        $res = $this->postJson("/api/kiosk/{$k->token}/identify", ['nationalId' => $c->national_id])
            ->assertOk();
        $res->assertJsonPath('candidate.participantCode', $a->participant_code);
        $token = $res->json('accessToken');
        $this->assertNotEmpty($token);

        // رقم الهوية يُعرض مقنّعاً: الشاشة في بهوٍ يقف خلفها آخرون
        $masked = $res->json('candidate.nationalIdMasked');
        $this->assertStringEndsWith(substr($c->national_id, -4), $masked);
        $this->assertStringNotContainsString($c->national_id, $masked);

        // الوصول يُنشئ نفس ReceptionVisit التي يصنعها كشف الاستقبال
        $this->postJson("/api/kiosk/{$k->token}/arrive", ['accessToken' => $token])->assertOk();
        $visit = ReceptionVisit::where('assessment_id', $a->id)->firstOrFail();
        $this->assertNotNull($visit->arrived_at);
        $this->assertNull($visit->received_by);          // تسجيل ذاتي لا موظّف
        $this->assertSame($k->id, $visit->kiosk_id);
        $this->assertNotNull($a->fresh()->arrived_at);   // الدورة متّسقة مع الزيارة
    }

    public function test_second_arrival_tap_does_not_create_a_second_visit(): void
    {
        [$c, $a] = $this->makeCandidate();
        $k = $this->kiosk();
        $token = $this->postJson("/api/kiosk/{$k->token}/identify", ['nationalId' => $c->national_id])
            ->json('accessToken');

        $this->postJson("/api/kiosk/{$k->token}/arrive", ['accessToken' => $token])->assertOk();
        $this->postJson("/api/kiosk/{$k->token}/arrive", ['accessToken' => $token])->assertOk();

        $this->assertSame(1, ReceptionVisit::where('assessment_id', $a->id)->count());
    }

    // ── نطاق الرمز ──

    public function test_yesterdays_token_and_revoked_token_are_both_dead(): void
    {
        [$c] = $this->makeCandidate();

        $stale = $this->kiosk(['kiosk_date' => now()->subDay()->toDateString()]);
        $this->postJson("/api/kiosk/{$stale->token}/identify", ['nationalId' => $c->national_id])
            ->assertStatus(404);

        $revoked = $this->kiosk(['revoked_at' => now()]);
        $this->postJson("/api/kiosk/{$revoked->token}/identify", ['nationalId' => $c->national_id])
            ->assertStatus(404);

        $this->getJson("/api/kiosk/{$stale->token}")->assertStatus(404);
    }

    public function test_access_token_of_one_kiosk_does_not_work_on_another(): void
    {
        [$c] = $this->makeCandidate();
        $a1 = $this->kiosk();
        $a2 = $this->kiosk();

        $token = $this->postJson("/api/kiosk/{$a1->token}/identify", ['nationalId' => $c->national_id])
            ->json('accessToken');

        $this->postJson("/api/kiosk/{$a2->token}/arrive", ['accessToken' => $token])
            ->assertStatus(401);
    }

    public function test_guessing_one_national_id_locks_out_after_five_attempts(): void
    {
        $k = $this->kiosk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/kiosk/{$k->token}/identify", ['nationalId' => '1000000000'])
                ->assertStatus(404);
        }
        // السادسة تُقفل — وسعة الكشك الواسعة لا تُعطي تخميناً غير محدود لشخص
        $this->postJson("/api/kiosk/{$k->token}/identify", ['nationalId' => '1000000000'])
            ->assertStatus(429)
            ->assertJsonPath('locked', true);
    }

    // ── ترتيب الخطوات ──

    public function test_signature_requires_arrival_and_badge_requires_signature(): void
    {
        [$c, $a] = $this->makeCandidate();
        $k = $this->kiosk();
        $token = $this->postJson("/api/kiosk/{$k->token}/identify", ['nationalId' => $c->national_id])
            ->json('accessToken');

        // توقيعٌ قبل الوصول: الإقرار وثيقةُ حضورٍ في يومٍ بعينه
        $this->postJson("/api/kiosk/{$k->token}/sign", [
            'accessToken' => $token, 'signature' => self::SIGNATURE, 'attested' => true,
        ])->assertStatus(422);

        $this->postJson("/api/kiosk/{$k->token}/arrive", ['accessToken' => $token])->assertOk();

        // بطاقةٌ قبل التوقيع: تُخرج مشاركاً في القاعة لم يقرّ بصحّة بياناته
        $this->postJson("/api/kiosk/{$k->token}/badge", ['accessToken' => $token])->assertStatus(422);

        // توقيعٌ بلا إقرار ليس إقراراً
        $this->postJson("/api/kiosk/{$k->token}/sign", [
            'accessToken' => $token, 'signature' => self::SIGNATURE, 'attested' => false,
        ])->assertStatus(422);

        $this->postJson("/api/kiosk/{$k->token}/sign", [
            'accessToken' => $token, 'signature' => self::SIGNATURE, 'attested' => true,
        ])->assertOk();

        $visit = ReceptionVisit::where('assessment_id', $a->id)->firstOrFail();
        $this->assertTrue($visit->isSigned());
        // التوقيع مشفَّر في العمود، لا خاماً
        $this->assertNotNull($visit->signature_enc);
        $this->assertStringNotContainsString('iVBORw0', $visit->signature_enc);

        $this->postJson("/api/kiosk/{$k->token}/badge", ['accessToken' => $token])
            ->assertOk()
            ->assertJsonPath('badge.participantCode', $a->participant_code);

        // البطاقة بلا اسم — تُبرَز أمام المقيّمين والتقييم دون معرفة الاسم
        $this->assertNull(
            $this->postJson("/api/kiosk/{$k->token}/badge", ['accessToken' => $token])->json('badge.name')
        );

        $this->assertTrue($visit->fresh()->badgePending());
    }

    public function test_kiosk_cannot_write_the_cv(): void
    {
        // البوّابة كانت تكتب السيرة؛ الكشك يعرض ويوقّع ولا يحرّر. المسار
        // غير مسجَّل أصلاً — لا محروسٌ بردّ 403.
        $k = $this->kiosk();
        $this->postJson("/api/kiosk/{$k->token}/cv", ['cv' => []])->assertStatus(404);
    }

    // ── طابور الطباعة عند مسؤول المرشحين ──

    public function test_badge_request_appears_in_the_officer_print_queue_then_clears(): void
    {
        [$c, $a] = $this->makeCandidate();
        $k = $this->kiosk();
        $token = $this->postJson("/api/kiosk/{$k->token}/identify", ['nationalId' => $c->national_id])
            ->json('accessToken');
        $this->postJson("/api/kiosk/{$k->token}/arrive", ['accessToken' => $token])->assertOk();
        $this->postJson("/api/kiosk/{$k->token}/sign", [
            'accessToken' => $token, 'signature' => self::SIGNATURE, 'attested' => true,
        ])->assertOk();
        $this->postJson("/api/kiosk/{$k->token}/badge", ['accessToken' => $token])->assertOk();

        $this->actingAsRole('RECEPTIONIST');
        $queue = $this->getJson('/api/reception/print-queue')->assertOk();
        $queue->assertJsonPath('queue.0.participantCode', $a->participant_code);

        $visitId = $queue->json('queue.0.visitId');
        $this->postJson("/api/reception/visits/{$visitId}/badge-printed")->assertOk();
        $this->getJson('/api/reception/print-queue')->assertJsonCount(0, 'queue');

        // إعادة الطباعة تُرجعها للطابور — بابها جهاز المسؤول لا الكشك
        $this->postJson("/api/reception/visits/{$visitId}/badge-reprint")->assertOk();
        $this->getJson('/api/reception/print-queue')->assertJsonCount(1, 'queue');
    }

    public function test_only_reception_record_holders_manage_kiosks_and_the_queue(): void
    {
        // المقيّم يملك reception.view ولا يملك reception.record: لا يُصدر
        // رمز كشك (بابُ تسجيل وصولٍ وتوقيع) ولا يرى طابور الطباعة
        $this->actingAsRole('EVALUATOR', 'DW');
        $this->postJson('/api/reception/kiosks')->assertStatus(403);
        $this->getJson('/api/reception/kiosks')->assertStatus(403);
        $this->getJson('/api/reception/print-queue')->assertStatus(403);

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/reception/kiosks', ['label' => 'إيباد البهو'])
            ->assertCreated()
            ->assertJsonPath('kiosk.label', 'إيباد البهو');
    }

    public function test_created_kiosk_link_is_scoped_to_today_and_revocable(): void
    {
        $this->actingAsRole('RECEPTIONIST');
        $url = $this->postJson('/api/reception/kiosks')->assertCreated()->json('kiosk.url');
        $id = $this->getJson('/api/reception/kiosks')->assertOk()->json('kiosks.0.id');

        $token = substr($url, strrpos($url, '/') + 1);
        $this->getJson("/api/kiosk/{$token}")->assertOk();

        $this->deleteJson("/api/reception/kiosks/{$id}")->assertOk();
        $this->getJson("/api/kiosk/{$token}")->assertStatus(404);
    }

    // ── البوّابة القديمة مغلقة ──

    public function test_the_disabled_candidate_portal_has_no_routes_left(): void
    {
        // إغلاق السطح لا حراسته: المسار غير مسجَّل، فلا يُعلن عن نفسه بـ403
        [$c, $a] = $this->makeCandidate();
        $this->postJson("/api/public/assessment/{$a->confirm_token}/verify", [
            'nationalId' => $c->national_id,
        ])->assertStatus(404);
    }
}
