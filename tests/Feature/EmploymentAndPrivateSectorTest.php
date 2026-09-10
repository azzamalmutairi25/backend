<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Schedule;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// الخدمة الأولى: ثلاثة تغييرات تُقاس هنا —
//   • الحالة الوظيفية: مسارٌ مستقلّ بيد مسؤول الجدولة وحده
//   • جهة العمل: للقطاع الخاص وحده، وتُهمَل لغيره
//   • «التمرين التكاملي» و«التصنيف الأمني»: خرجا من المفردات والشاشات
class EmploymentAndPrivateSectorTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // ═══ الحالة الوظيفية ═══

    public function test_scheduling_officer_sets_the_employment_status(): void
    {
        [$c] = $this->makeCandidate();
        $this->assertSame('active', $c->fresh()->employment_status, 'الافتراض: على رأس العمل');

        $this->actingAsRole('SCHEDULER');
        $this->patchJson("/api/candidates/{$c->id}/employment", ['employmentStatus' => 'retired'])
            ->assertOk()
            ->assertJsonPath('employmentStatus', 'retired');

        $this->assertSame('retired', $c->fresh()->employment_status);
    }

    // الحقل ليس في التعديل العامّ: من يملأ البيانات لا يبتّ في حال صاحبها
    public function test_a_data_entry_editor_cannot_change_it(): void
    {
        [$c] = $this->makeCandidate();

        $this->actingAsRole('EXTERNAL_ADD');
        $this->patchJson("/api/candidates/{$c->id}/employment", ['employmentStatus' => 'retired'])
            ->assertStatus(403);

        $this->assertSame('active', $c->fresh()->employment_status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        [$c] = $this->makeCandidate();
        $this->actingAsRole('SCHEDULER');

        $this->patchJson("/api/candidates/{$c->id}/employment", ['employmentStatus' => 'onleave'])
            ->assertStatus(422);
    }

    public function test_the_change_is_audited_with_before_and_after(): void
    {
        [$c] = $this->makeCandidate();
        $this->actingAsRole('SCHEDULER');
        $this->patchJson("/api/candidates/{$c->id}/employment", ['employmentStatus' => 'retired'])->assertOk();

        $row = DB::table('audit_logs')->where('action', 'UPDATE_EMPLOYMENT_STATUS')->first();
        $this->assertNotNull($row, 'تغييرُ حالٍ وظيفيّ يُدوَّن');
        $details = json_decode($row->details, true);
        $this->assertSame('active', $details['from']);
        $this->assertSame('retired', $details['to']);
    }

    public function test_setting_the_same_status_is_a_no_op(): void
    {
        [$c] = $this->makeCandidate();
        $this->actingAsRole('SCHEDULER');

        $this->patchJson("/api/candidates/{$c->id}/employment", ['employmentStatus' => 'active'])
            ->assertOk()->assertJsonPath('message', 'لا تغيير');

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'UPDATE_EMPLOYMENT_STATUS')->count());
    }

    public function test_the_list_filters_by_employment_status(): void
    {
        [$a] = $this->makeCandidate();
        [$b] = $this->makeCandidate();
        $b->forceFill(['employment_status' => 'retired'])->save();

        $this->actingAsRole('SCHEDULER');
        $codes = collect($this->getJson('/api/candidates?employmentStatus=retired')->assertOk()->json('candidates'))
            ->pluck('id')->all();

        $this->assertSame([$b->id], $codes);
    }

    // ═══ جهة العمل ═══

    public function test_employer_is_kept_for_the_private_sector(): void
    {
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/candidates', $this->newCandidatePayload([
            'personnelCategory' => 'contractor',
            'rankLabel' => 'مستشار تقنية المعلومات',
            'tier' => 'middle',
            'employer' => 'شركة الاتصالات السعودية',
        ]))->assertCreated();

        $this->assertSame('شركة الاتصالات السعودية', Candidate::latest('id')->first()->employer);
    }

    // مدنيٌّ أو عسكريّ لا جهةَ عملٍ له: قطاعُه هو جهته، ونصٌّ باقٍ بعد تصحيح
    // الفئة يُقرأ في الخطاب على أنه جهةٌ خارجية
    public function test_employer_is_dropped_for_other_categories(): void
    {
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/candidates', $this->newCandidatePayload([
            'personnelCategory' => 'military',
            'rankLabel' => 'عقيد',
            'employer' => 'جهةٌ لا تخصّه',
        ]))->assertCreated();

        $this->assertNull(Candidate::latest('id')->first()->employer);
    }

    // ═══ «قطاع خاص» تسميةً ═══

    public function test_the_category_label_reads_private_sector(): void
    {
        $this->assertSame('قطاع خاص', Candidate::categoryLabel('contractor'));
        $this->assertSame('المسمّى الوظيفي', Candidate::rankWord('contractor'));
    }

    // ═══ المفردات المنزوعة ═══

    public function test_the_integration_activity_is_no_longer_schedulable(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'integration',
            'date' => now()->addDay()->toDateString(), 'time' => '09:30',
        ])->assertStatus(422);

        $this->assertSame(0, Schedule::where('activity', 'integration')->count());
    }

    public function test_the_classification_route_is_gone(): void
    {
        [$c] = $this->makeCandidate();
        $this->actingAsRole('ADMIN');

        $this->patchJson("/api/candidates/{$c->id}/classify", ['classification' => 'secret'])
            ->assertStatus(404);
    }

    public function test_a_new_candidate_is_always_normal(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/candidates', $this->newCandidatePayload([
            'classification' => 'top_secret',   // يُهمَل: الحقل خرج من المفردات
        ]))->assertCreated();

        $this->assertSame('normal', Candidate::latest('id')->first()->classification);
    }

    // ── حمولة إضافةٍ صالحة، تُعدَّل بما يخصّ كل حالة ──
    private function newCandidatePayload(array $overrides = []): array
    {
        return array_merge([
            'nationalId' => $this->validNationalId(),
            'fullName' => 'مشارك اختبار',
            'sectorId' => Sector::where('code', 'DW')->firstOrFail()->id,
            'personnelCategory' => 'civilian',
            'rankLabel' => 'الرابعة عشرة',
            'gender' => 'male',
        ], $overrides);
    }
}
