<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// رمز مشارك القطاع قابل للتحديد من الإعدادات؛ الأرقام تبقى تلقائية بعده.
class SectorPrefixTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_prefix_is_only_visible_to_settings_managers(): void
    {
        $this->actingAsRole('SCHEDULER'); // لا SETTINGS_MANAGE
        $res = $this->getJson('/api/sectors')->assertOk();
        $this->assertArrayNotHasKey('participantPrefix', $res->json('sectors.0'));

        $this->actingAsRole('ADMIN');
        $res = $this->getJson('/api/sectors')->assertOk();
        $this->assertArrayHasKey('participantPrefix', $res->json('sectors.0'));
    }

    public function test_updating_prefix_requires_settings_manage(): void
    {
        $s = Sector::first();
        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/sectors/{$s->id}/prefix", ['prefix' => 'XX'])->assertStatus(403);
    }

    public function test_new_codes_use_the_updated_prefix(): void
    {
        $s = Sector::where('code', 'ED')->first();
        $this->actingAsRole('ADMIN');

        $this->putJson("/api/sectors/{$s->id}/prefix", ['prefix' => 'EDU'])->assertOk();

        $code = Assessment::generateParticipantCode($s->fresh());
        $this->assertStringStartsWith('EDU-', $code);
    }

    public function test_existing_codes_are_not_rewritten(): void
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'ED']);
        $oldCode = $a->participant_code;

        $s = Sector::where('code', 'ED')->first();
        $this->actingAsRole('ADMIN');
        $this->putJson("/api/sectors/{$s->id}/prefix", ['prefix' => 'EDU'])->assertOk();

        // الرمز مُثبَّت على الدورة لا مشتقّ عند العرض — لا يتغيّر بأثر رجعي
        $this->assertSame($oldCode, $a->fresh()->participant_code);
    }

    public function test_duplicate_prefix_is_rejected(): void
    {
        $ho = Sector::where('code', 'HO')->first();
        $this->actingAsRole('ADMIN');

        // HO بادئته الافتراضية HO؛ محاولة إعطاء التعليم نفس البادئة تُرفض
        $this->putJson("/api/sectors/{$ho->id}/prefix", ['prefix' => 'HO'])->assertOk();
        $ed = Sector::where('code', 'ED')->first();
        $this->putJson("/api/sectors/{$ed->id}/prefix", ['prefix' => 'HO'])
            ->assertStatus(422);
    }

    public function test_invalid_prefix_format_is_rejected(): void
    {
        $s = Sector::first();
        $this->actingAsRole('ADMIN');

        foreach (['a', 'toolong', 'ا ب', 'X-Y'] as $bad) {
            $this->putJson("/api/sectors/{$s->id}/prefix", ['prefix' => $bad])->assertStatus(422);
        }
    }

    public function test_prefix_change_is_audited(): void
    {
        $s = Sector::where('code', 'ED')->first();
        $this->actingAsRole('ADMIN');
        $this->putJson("/api/sectors/{$s->id}/prefix", ['prefix' => 'EDU'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'UPDATE_SECTOR_PREFIX']);
    }
}
