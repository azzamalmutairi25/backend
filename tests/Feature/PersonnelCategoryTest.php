<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Rank;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  فئة المنسوب: مدني / عسكري / متعاقد — صفةُ الشخص لا صفةُ قطاعه.
//
//  كانت مُعلَّقة على القطاع، فالقطاع الواحد يُجبر منسوبيه على قائمةِ رتبٍ
//  واحدة: مدنيٌّ في «الأمن العام» يُطلب منه رتبةٌ عسكرية. هذه الحزمة تثبت
//  أن القطاع الواحد يسع الأصناف الثلاثة، وأن كلاً منها يُصنَّف بقائمته.
// ════════════════════════════════════════════════════════════
class PersonnelCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function payload(array $over = []): array
    {
        return array_merge([
            'nationalId' => $this->validNationalId(),
            'fullName' => 'مرشح اختبار',
            'mobile' => '0501112223',
            'sectorId' => Sector::where('code', 'PS')->value('id'),
            'personnelCategory' => 'civilian',
            'rankLabel' => 'الرابعة عشرة',
        ], $over);
    }

    // ── جوهر التغيير: قطاعٌ واحد يسع الأصناف الثلاثة ──
    public function test_one_sector_holds_all_three_categories(): void
    {
        $this->actingAsRole('SCHEDULER');
        $sectorId = Sector::where('code', 'PS')->value('id');

        $this->postJson('/api/candidates', $this->payload([
            'sectorId' => $sectorId, 'personnelCategory' => 'military', 'rankLabel' => 'عميد',
        ]))->assertStatus(201);

        $this->postJson('/api/candidates', $this->payload([
            'sectorId' => $sectorId, 'personnelCategory' => 'civilian', 'rankLabel' => 'الرابعة عشرة',
        ]))->assertStatus(201);

        $this->postJson('/api/candidates', $this->payload([
            'sectorId' => $sectorId, 'personnelCategory' => 'contractor',
            'rankLabel' => 'مستشار تقنية المعلومات', 'tier' => 'upper',
        ]))->assertStatus(201);

        $this->assertSame(
            ['civilian' => 1, 'contractor' => 1, 'military' => 1],
            Candidate::where('sector_id', $sectorId)
                ->get()->groupBy('personnel_category')->map->count()->sortKeys()->all(),
        );
    }

    // الطبقة تُحسب من قائمة الفئة — لا من قطاع المرشّح
    public function test_tier_follows_the_candidates_own_category(): void
    {
        $this->actingAsRole('SCHEDULER');

        // «عميد» عليا في الرتب العسكرية
        $this->postJson('/api/candidates', $this->payload([
            'personnelCategory' => 'military', 'rankLabel' => 'عميد',
        ]))->assertStatus(201)->assertJsonPath('tier', 'upper');

        // و«السادسة» وسطى في المراتب المدنية — في القطاع نفسه
        $this->postJson('/api/candidates', $this->payload([
            'personnelCategory' => 'civilian', 'rankLabel' => 'السادسة',
        ]))->assertStatus(201)->assertJsonPath('tier', 'middle');
    }

    // ── المتعاقد ──
    // مسمّاه حرّ فلا قائمة تُطابَق عليها، وطبقتُه تُرسَل صراحةً
    public function test_contractor_takes_a_free_title_and_an_explicit_tier(): void
    {
        $this->actingAsRole('SCHEDULER');

        $code = $this->postJson('/api/candidates', $this->payload([
            'personnelCategory' => 'contractor',
            'rankLabel' => 'مستشار تقنية المعلومات',
            'tier' => 'upper',
        ]))->assertStatus(201)->json('participantCode');

        $c = Candidate::where('participant_code', $code)->firstOrFail();
        $this->assertSame('contractor', $c->personnel_category);
        $this->assertSame('مستشار تقنية المعلومات', $c->rank_label);
        $this->assertSame('upper', $c->tier, 'طبقة المتعاقد المرسَلة لم تُحفظ');
    }

    public function test_contractor_without_a_tier_is_rejected(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/candidates', $this->payload([
            'personnelCategory' => 'contractor', 'rankLabel' => 'مستشار', 'tier' => null,
        ]))->assertStatus(422);
    }

    // استنتاج طبقة المتعاقد من مسمّىً حرّ تخمينٌ يُقيَّم به إنسان — يُمنع في المنبع
    public function test_classify_tier_refuses_to_guess_for_a_contractor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Candidate::classifyTier('مستشار تقنية المعلومات', 'contractor');
    }

    public function test_category_is_required_and_validated(): void
    {
        $this->actingAsRole('SCHEDULER');

        $missing = $this->payload();
        unset($missing['personnelCategory']);
        $this->postJson('/api/candidates', $missing)->assertStatus(422);

        $this->postJson('/api/candidates', $this->payload(['personnelCategory' => 'ضابط']))
            ->assertStatus(422);
    }

    public function test_category_is_returned_by_the_api(): void
    {
        $this->actingAsRole('SCHEDULER');
        $code = $this->postJson('/api/candidates', $this->payload([
            'personnelCategory' => 'military', 'rankLabel' => 'عميد',
        ]))->assertStatus(201)->json('participantCode');
        $id = Candidate::where('participant_code', $code)->value('id');

        $this->getJson("/api/candidates/{$id}")->assertOk()
            ->assertJsonPath('candidate.personnelCategory', 'military');
    }

    // الرتبة المُدارة تُقرأ بفئة المرشّح: نفس التسمية في القائمتين تُصنَّف بحسبها
    public function test_managed_rank_lookup_is_scoped_to_the_category(): void
    {
        Rank::create(['label' => 'مراقب', 'category' => 'military', 'tier' => 'upper', 'sort_order' => 900, 'is_active' => true]);
        Rank::create(['label' => 'مراقب', 'category' => 'civilian', 'tier' => 'middle', 'sort_order' => 900, 'is_active' => true]);

        $this->assertSame('upper', Candidate::classifyTier('مراقب', 'military'));
        $this->assertSame('middle', Candidate::classifyTier('مراقب', 'civilian'));
    }
}
