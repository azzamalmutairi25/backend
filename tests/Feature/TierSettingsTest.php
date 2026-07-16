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
        $this->assertSame('upper', Candidate::classifyTier('لواء ركن', true));
        $this->assertSame('middle', Candidate::classifyTier('رائد', true));
        $this->assertSame('upper', Candidate::classifyTier('م-14', false));
        $this->assertSame('middle', Candidate::classifyTier('م-11', false));
    }

    public function test_saved_military_ranks_change_classification(): void
    {
        Setting::updateOrCreate(['key' => 'tier.military_upper_ranks'], ['value' => 'عقيد,عميد']);
        // «عقيد» صار عليا بعد إضافته، و«لواء» لم يعد ضمن القائمة → وسطى
        $this->assertSame('upper', Candidate::classifyTier('عقيد ركن', true));
        $this->assertSame('middle', Candidate::classifyTier('لواء', true));
    }

    public function test_saved_civilian_grade_threshold_changes_classification(): void
    {
        Setting::updateOrCreate(['key' => 'tier.civilian_upper_grade'], ['value' => '15']);
        $this->assertSame('middle', Candidate::classifyTier('م-14', false)); // كانت عليا عند العتبة 13
        $this->assertSame('upper', Candidate::classifyTier('م-15', false));
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
        $this->assertSame('upper', Candidate::classifyTier('م-12', false));
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
