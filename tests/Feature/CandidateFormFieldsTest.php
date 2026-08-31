<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\TechnicalArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  حقول نموذج المشارك بعد إعادة تشكيله
//
//  ثلاثة تغييرات يسهل أن تنقلب صامتةً، فلكلٍّ منها محكّ:
//   ١) المجالات الفنية اختيارية عند الإضافة، إلزامية عند التعديل. وأخطر ما
//      فيها أن غيابها لا يعني تفريغها — العائد يحتفظ بمجالات دورته السابقة.
//   ٢) البريد الإلكتروني رُفع: لا يُكتب ولا يُقرأ. والخطر أن يُترك إسنادُه
//      بعد حذف قاعدة تحقّقه فيُفرَّغ بريد كل من يُعدَّل.
//   ٣) نوع التقييم صار «شامل» أو «طلب خاص»، و«تنفيذي» لم يعد يُقبل.
// ════════════════════════════════════════════════════════════
class CandidateFormFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function payload(array $over = []): array
    {
        return array_replace([
            'nationalId' => $this->validNationalId(),
            'fullName' => 'مشارك اختبار',
            'sectorId' => \App\Models\Sector::first()->id,
            'gender' => 'male',
            'personnelCategory' => 'civilian',
            'rankLabel' => 'الرابعة عشرة',
            'cv' => $this->validCvDoc(),
        ], $over);
    }

    // ── ١) المجالات الفنية ──
    public function test_a_candidate_saves_without_technical_areas_and_is_flagged(): void
    {
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/candidates', $this->payload())
            ->assertStatus(201)
            ->assertJson(['needsTechnicalAreas' => true])
            ->assertJsonStructure(['candidateId']);
    }

    public function test_sending_areas_at_creation_still_works_and_clears_the_flag(): void
    {
        $this->actingAsRole('SCHEDULER');
        $ids = TechnicalArea::query()->limit(2)->pluck('id')->all();

        $res = $this->postJson('/api/candidates', $this->payload(['technicalAreaIds' => $ids]))
            ->assertStatus(201)
            ->assertJson(['needsTechnicalAreas' => false]);

        $c = Candidate::find($res->json('candidateId'));
        $this->assertEqualsCanonicalizing($ids, $c->technicalAreas->pluck('id')->all());
    }

    // كانت الشاشة تشترط مجالاً واحداً في التعديل وحده. رُفع الإلزام (راجع
    // config/participants.php)، والثمن مُعلَن كما كان: مشاركٌ بلا مجال لا يظهر
    // في قوائم الترشيح — ولذلك يبقى العَلَم `needsTechnicalAreas` يسوق إليها.
    public function test_the_edit_screen_no_longer_demands_an_area(): void
    {
        [$c] = $this->makeCandidate();
        $nid = $c->national_id;
        $this->actingAsRole('SCHEDULER');

        $this->putJson("/api/candidates/{$c->id}", [
            'nationalId' => $nid, 'fullName' => 'محدّث', 'sectorId' => $c->sector_id,
            'gender' => 'male', 'personnelCategory' => 'civilian', 'rankLabel' => 'الرابعة عشرة',
            'technicalAreaIds' => [],
        ])->assertOk();

        $this->assertSame([], $c->fresh()->technicalAreas->pluck('id')->all());
    }

    // أخطر أثرٍ لجعلها اختيارية: sync([]) على العائد كان يمحو مجالات دورته
    // السابقة لمجرّد أن النموذج لم يعد يرسلها
    public function test_a_returning_candidate_keeps_areas_when_none_are_sent(): void
    {
        [$c] = $this->makeCandidate(['status' => 'completed', 'assessmentStatus' => 'completed']);
        $ids = TechnicalArea::query()->limit(2)->pluck('id')->all();
        $c->technicalAreas()->sync($ids);
        $nid = $c->national_id;

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/candidates', $this->payload(['nationalId' => $nid]))
            ->assertStatus(201)
            ->assertJson(['isReturning' => true, 'needsTechnicalAreas' => false]);

        $this->assertEqualsCanonicalizing($ids, $c->fresh()->technicalAreas->pluck('id')->all());
    }

    // ── ٢) البريد الإلكتروني ──
    // كان العمود المشفّر قائماً بلا مسار كتابةٍ إليه إطلاقاً: تُرسَل القيمة
    // فتُهمَل بصمت، فلا تصل صاحبَها دعوةٌ بالبريد أبداً. الآن يُقبل ويُخزَّن
    // مشفّراً، ويعود خلف صلاحية عرض البيانات الشخصية كالجوال تماماً.
    public function test_email_is_stored_at_creation_and_returned_to_the_privileged(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->postJson('/api/candidates', $this->payload(['email' => 'x@moi.gov.sa']))
            ->assertStatus(201);

        $c = Candidate::find($res->json('candidateId'));
        $this->assertSame('x@moi.gov.sa', $c->email);
        // مشفّرٌ في القاعدة لا نصّاً — العمود العاري يُبطل تشفير الجدول
        $this->assertNotSame('x@moi.gov.sa', $c->getRawOriginal('email_enc'));

        $this->getJson("/api/candidates/{$c->id}")
            ->assertOk()
            ->assertJsonPath('candidate.email', 'x@moi.gov.sa');
    }

    // الرقم العسكري/الوظيفي — مُعرِّفٌ مباشر يسلك مسلك الجوال والبريد
    public function test_military_number_is_stored_encrypted_and_returned(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->postJson('/api/candidates', $this->payload(['militaryNumber' => '480321']))
            ->assertStatus(201);

        $c = Candidate::find($res->json('candidateId'));
        $this->assertSame('480321', $c->military_number);
        $this->assertNotSame('480321', $c->getRawOriginal('military_number_enc'));

        $this->getJson("/api/candidates/{$c->id}")
            ->assertOk()
            ->assertJsonPath('candidate.militaryNumber', '480321');
    }

    // الفخّ: حذف قاعدة التحقّق مع بقاء الإسناد كان يُفرّغ بريد كل من يُعدَّل
    public function test_editing_a_candidate_does_not_wipe_a_previously_stored_email(): void
    {
        [$c] = $this->makeCandidate();
        $c->email = 'legacy@moi.gov.sa';
        $c->save();
        $nid = $c->national_id;

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/candidates/{$c->id}", [
            'nationalId' => $nid, 'fullName' => 'محدّث', 'sectorId' => $c->sector_id,
            'gender' => 'male', 'personnelCategory' => 'civilian', 'rankLabel' => 'الرابعة عشرة',
            'technicalAreaIds' => TechnicalArea::query()->limit(1)->pluck('id')->all(),
        ])->assertOk();

        $this->assertSame('legacy@moi.gov.sa', $c->fresh()->email);
    }

    // ── ٣) نوع التقييم ──
    public function test_special_request_is_accepted_and_executive_is_not(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->postJson('/api/candidates', $this->payload(['assessmentType' => 'special_request']))
            ->assertStatus(201);
        $this->assertSame('special_request', Candidate::find($res->json('candidateId'))->assessment_type);

        $this->postJson('/api/candidates', $this->payload(['assessmentType' => 'executive']))
            ->assertStatus(422);
    }
}
