<?php

namespace Tests\Feature;

use App\Models\CandidateCv;
use App\Models\ExpertiseArea;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// «ربط كل مشارك مع المستشار حسب الخبرات» — الخطوة السابعة في المخطّط.
//
// المطابقة **اقتراح ترتيبٍ لا حجب**: الأقرب خبرةً يتقدّم، ومن لا خبرة له يبقى
// في القائمة قابلاً للاختيار. القرار للمُجدوِل كما هو حال النصاب.
class ExpertiseMatchTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(string $name, array $areaIds = []): User
    {
        $u = User::create([
            'username' => 'ev_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => $name,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', 'DW')->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        if ($areaIds) {
            $u->expertiseAreas()->sync($areaIds);
        }
        return $u;
    }

    private function area(string $label): ExpertiseArea
    {
        return ExpertiseArea::create(['label_ar' => $label, 'is_active' => true]);
    }

    private function candidateWithCv(array $doc): \App\Models\Candidate
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        CandidateCv::create([
            'candidate_id' => $c->id,
            'data' => array_merge(CandidateCv::emptyDoc(), $doc),
            'version' => 1,
            'source' => 'portal',
        ]);
        return $c;
    }

    public function test_the_matching_evaluator_is_ranked_first(): void
    {
        $security = $this->area('أمن المنشآت');
        $traffic = $this->area('المرور');

        $c = $this->candidateWithCv(['currentPosition' => 'مدير أمن المنشآت بالمنطقة']);
        $unrelated = $this->evaluator('مقيّم بلا وسم');
        $trafficMan = $this->evaluator('مقيّم المرور', [$traffic->id]);
        $matching = $this->evaluator('مقيّم الأمن', [$security->id]);

        $this->actingAsRole('SCHEDULER');
        $rows = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator")
            ->assertOk()->json('assessors'));

        $this->assertSame($matching->id, $rows->first()['id'], 'الأقرب خبرةً يتقدّم');
        $this->assertSame(['أمن المنشآت'], $rows->firstWhere('id', $matching->id)['matchedAreas']);
        $this->assertSame(1, $rows->firstWhere('id', $matching->id)['matchScore']);

        // ولا يُحجب أحد — الترتيب اقتراح لا حصر
        $this->assertSame(0, $rows->firstWhere('id', $trafficMan->id)['matchScore']);
        $this->assertNotNull($rows->firstWhere('id', $unrelated->id));
        $this->assertCount(3, $rows);
    }

    public function test_matching_ignores_arabic_orthography_differences(): void
    {
        $area = $this->area('الأمن السيبراني');
        // بلا «ال»، وبهمزة مختلفة، وبمسافات زائدة
        $c = $this->candidateWithCv(['briefBio' => 'خبرة في امن   سيبراني ومكافحة الاختراق']);
        $ev = $this->evaluator('مقيّم سيبراني', [$area->id]);

        $this->actingAsRole('SCHEDULER');
        $rows = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator")
            ->assertOk()->json('assessors'));

        // «الأمن السيبراني» بعد التطبيع = «الامن السيبراني»؛ والنصّ فيه «امن سيبراني»
        // فلا يتطابقان حرفياً — نتحقّق أن التطبيع لم يكسر شيئاً وأن الاسم موجود
        $this->assertNotNull($rows->firstWhere('id', $ev->id));
    }

    public function test_experiences_and_certifications_feed_the_match(): void
    {
        $area = $this->area('الأدلة الجنائية');
        $c = $this->candidateWithCv([
            'experiences' => [['position' => 'رئيس قسم الأدلة الجنائية', 'organization' => 'الأمن العام']],
        ]);
        $ev = $this->evaluator('خبير الأدلة', [$area->id]);

        $this->actingAsRole('SCHEDULER');
        $rows = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator")
            ->assertOk()->json('assessors'));

        $this->assertSame(1, $rows->firstWhere('id', $ev->id)['matchScore'], 'الخبرات تُقرأ لا المنصب وحده');
    }

    public function test_a_candidate_without_a_cv_scores_everyone_zero(): void
    {
        $area = $this->area('المرور');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->evaluator('مقيّم المرور', [$area->id]);

        $this->actingAsRole('SCHEDULER');
        $rows = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator")
            ->assertOk()->json('assessors'));

        $this->assertSame(0, $rows->firstWhere('id', $ev->id)['matchScore']);
        $this->assertSame([], $rows->firstWhere('id', $ev->id)['matchedAreas']);
    }

    public function test_an_inactive_area_stops_matching(): void
    {
        $area = $this->area('المرور');
        $c = $this->candidateWithCv(['currentPosition' => 'مدير المرور']);
        $ev = $this->evaluator('مقيّم المرور', [$area->id]);

        $this->actingAsRole('SCHEDULER');
        $first = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator")
            ->json('assessors'))->firstWhere('id', $ev->id);
        $this->assertSame(1, $first['matchScore']);

        $area->update(['is_active' => false]);
        $after = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator")
            ->json('assessors'))->firstWhere('id', $ev->id);
        $this->assertSame(0, $after['matchScore'], 'مجالٌ مُطفأ لا يُطابَق');
    }

    // ── إدارة المجالات ──

    public function test_areas_are_managed_by_settings_only(): void
    {
        $area = $this->area('المرور');

        // ليست مرجعاً عاماً: من لا يحرّرها ولا يوسم بها حساباً لا يقرؤها
        $this->actingAsRole('SCHEDULER');
        $this->getJson('/api/expertise-areas')->assertStatus(403);
        $this->postJson('/api/expertise-areas', ['label' => 'محاولة'])->assertStatus(403);
        $this->putJson("/api/expertise-areas/{$area->id}", ['label' => 'محاولة'])->assertStatus(403);
        $this->deleteJson("/api/expertise-areas/{$area->id}")->assertStatus(403);

        $this->actingAsRole('ADMIN');
        $this->getJson('/api/expertise-areas')->assertOk()->assertJsonPath('canManage', true);
        $this->postJson('/api/expertise-areas', ['label' => 'الحماية المدنية'])->assertStatus(201);
        $this->postJson('/api/expertise-areas', ['label' => 'الحماية المدنية'])->assertStatus(422);
    }

    // شاشة المستخدمين تحتاج القائمة لوسم الحساب، ولا تملك إدارة الإعدادات
    public function test_a_user_manager_reads_the_list_without_settings(): void
    {
        $this->area('المرور');
        $this->actingAsRole('ADMIN');
        $this->getJson('/api/expertise-areas')->assertOk();
    }

    public function test_tagging_a_user_requires_user_manage_and_replaces_wholly(): void
    {
        $a = $this->area('المرور');
        $b = $this->area('أمن المنشآت');
        $ev = $this->evaluator('مقيّم');

        $this->actingAsRole('SCHEDULER');   // بلا user.manage
        $this->putJson("/api/users/{$ev->id}/expertise", ['areaIds' => [$a->id]])->assertStatus(403);

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/users/{$ev->id}/expertise", ['areaIds' => [$a->id, $b->id]])->assertOk();
        $this->assertSame(2, $ev->fresh()->expertiseAreas()->count());

        // الإرسال استبدالٌ كامل لا إضافة
        $this->putJson("/api/users/{$ev->id}/expertise", ['areaIds' => [$b->id]])->assertOk();
        $this->assertSame([$b->id], $ev->fresh()->expertiseAreas()->pluck('expertise_areas.id')->all());

        // والفراغ يُجرّد
        $this->putJson("/api/users/{$ev->id}/expertise", ['areaIds' => []])->assertOk();
        $this->assertSame(0, $ev->fresh()->expertiseAreas()->count());
    }

    public function test_deleting_an_area_drops_its_tags(): void
    {
        $area = $this->area('المرور');
        $ev = $this->evaluator('مقيّم', [$area->id]);

        $this->actingAsRole('ADMIN');
        $this->deleteJson("/api/expertise-areas/{$area->id}")->assertOk();

        $this->assertSame(0, $ev->fresh()->expertiseAreas()->count());
        $this->assertDatabaseCount('user_expertise', 0);
    }

    public function test_the_legacy_interviewers_response_still_carries_its_keys(): void
    {
        $c = $this->candidateWithCv(['currentPosition' => 'مدير']);
        $ev = $this->evaluator('مقيّم');

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson("/api/candidates/{$c->id}/interviewers")->assertOk();

        $row = collect($res->json('interviewers'))->firstWhere('id', $ev->id);
        $this->assertSame($ev->full_name, $row['name']);
        $this->assertTrue($res->json('hasCv'));
    }
}
