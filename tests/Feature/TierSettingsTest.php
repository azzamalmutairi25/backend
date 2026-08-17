<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// تصنيف القيادة (عليا/وسطى) قابل للضبط من الإعدادات، مع رجوع لقيم افتراضية.
class TierSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_defaults_apply_when_unset(): void
    {
        $this->assertSame('upper', Candidate::classifyTier('لواء ركن', 'military'));
        $this->assertSame('middle', Candidate::classifyTier('رائد', 'military'));
        $this->assertSame('upper', Candidate::classifyTier('م-14', 'civilian'));
        $this->assertSame('middle', Candidate::classifyTier('م-11', 'civilian'));
    }

    // ── ترتيب المصدرين: جدول الرتب المُدار ثم قائمة الإعدادات ──
    //
    // كان هنا اختبارٌ يفترض أن قائمة الإعدادات وحدها تحكم الرتب العسكرية،
    // وهو ما كان صحيحاً قبل جدول `ranks`. بعد زرعه صار الجدول يفوز على
    // الإعداد — بقرارٍ صريح موثَّق في هجرة الزرع: تعديلُ طبقة رتبةٍ مزروعة
    // بابُه شاشة الرتب، كي لا يقلب حفظٌ في الإعدادات تصنيفاً ضبطه المدير
    // رتبةً رتبة. فالاختبار الآن يثبّت الترتيب نفسه لا أحد طرفيه.

    public function test_managed_ranks_win_over_the_settings_list(): void
    {
        // «عقيد» مزروعة وسطى و«لواء» مزروعة عليا. الإعداد يقلبهما نصّاً:
        // يرفع عقيد ويُسقط لواء — ولا أثر لذلك على المزروعتين.
        Setting::updateOrCreate(['key' => 'tier.military_upper_ranks'], ['value' => 'عقيد,عميد']);

        $this->assertSame('middle', Candidate::classifyTier('عقيد ركن', 'military'));
        $this->assertSame('upper', Candidate::classifyTier('لواء', 'military'));
    }

    public function test_settings_list_governs_ranks_outside_the_managed_list(): void
    {
        // «مشير» و«فريق» ليستا في الرتب المزروعة، فتسقطان إلى قائمة الإعدادات
        Setting::updateOrCreate(['key' => 'tier.military_upper_ranks'], ['value' => 'مشير']);

        $this->assertSame('upper', Candidate::classifyTier('مشير', 'military'));
        $this->assertSame('middle', Candidate::classifyTier('فريق', 'military'));
    }

    public function test_saved_civilian_grade_threshold_changes_classification(): void
    {
        Setting::updateOrCreate(['key' => 'tier.civilian_upper_grade'], ['value' => '15']);
        $this->assertSame('middle', Candidate::classifyTier('م-14', 'civilian')); // كانت عليا عند العتبة 13
        $this->assertSame('upper', Candidate::classifyTier('م-15', 'civilian'));
    }

    public function test_tier_settings_editable_only_by_settings_managers(): void
    {
        $this->actingAsRole('SCHEDULER'); // لا SETTINGS_MANAGE
        $this->getJson('/api/settings/tier')->assertStatus(403);
        $this->putJson('/api/settings/tier', ['militaryUpperRanks' => 'عميد', 'civilianUpperGrade' => 13])->assertStatus(403);

        $this->actingAsRole('ADMIN');
        $this->getJson('/api/settings/tier')->assertOk()->assertJsonPath('tier.civilianUpperGrade', 13);
        $this->putJson('/api/settings/tier', ['militaryUpperRanks' => 'عميد، لواء، فريق', 'civilianUpperGrade' => 12])
            ->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'UPDATE_TIER_RULES']);
        // تُطبَّق فوراً على تصنيف مرشّح جديد
        $this->assertSame('upper', Candidate::classifyTier('م-12', 'civilian'));
    }

    public function test_tier_rejects_out_of_range_grade_and_empty_ranks(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/tier', ['militaryUpperRanks' => 'عميد', 'civilianUpperGrade' => 99])->assertStatus(422);
        $this->putJson('/api/settings/tier', ['militaryUpperRanks' => '  ،، ', 'civilianUpperGrade' => 13])->assertStatus(422);
    }

    public function test_get_tier_shows_defaults_before_any_save(): void
    {
        $this->actingAsRole('ADMIN');
        $res = $this->getJson('/api/settings/tier')->assertOk();
        $this->assertStringContainsString('عميد', $res->json('tier.militaryUpperRanks'));
        $this->assertSame(13, $res->json('tier.civilianUpperGrade'));
    }
}
