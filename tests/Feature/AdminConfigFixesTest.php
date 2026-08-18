<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DevelopmentPlanItem;
use App\Models\Evaluation;
use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// إصلاحات مراجعة الإدارة/الإعدادات + التدقيق:
//  - خطة التطوير تحترم تضييق المقيّم (لا يرى خطة مشارك قطاعه لم يقيّمه).
//  - seed خطة التطوير يبقى غير مكرِّر (تسلسل بقفل صف المشارك).
//  - سجل التدقيق يحجب تفاصيل صفوف الكيانات المرتبطة بمشارك مصنّف عن غير المصرَّح له.
class AdminConfigFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // ── Fix 1: المقيّم لا يرى خطة تطوير مشارك قطاعه لم يقيّمه ──
    public function test_development_plan_index_respects_evaluator_narrowing(): void
    {
        $evaluator = $this->actingAsRole('EVALUATOR', 'DW'); // يملك REPORT_VIEW، محصور

        // مشارك لم يقيّمه هذا المقيّم — يُحجب (404)
        [$notMine] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'assessed']);
        $this->getJson("/api/development-plans/{$notMine->id}")->assertStatus(404);

        // مشارك قيّمه هذا المقيّم — مرئي (200)
        [$mine, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'assessed']);
        Evaluation::create([
            'candidate_id' => $mine->id, 'assessment_id' => $a->id,
            'evaluator_id' => $evaluator->id, 'activity' => 'interview', 'status' => 'draft',
        ]);
        $this->getJson("/api/development-plans/{$mine->id}")->assertOk();
    }

    // ── Fix 2: إعادة توليد الخطة لا تُكرّر البنود ──
    public function test_seed_is_idempotent(): void
    {
        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_CREATE + view_classified
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'assessed']);
        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => 'pending_evaluator',
            'development_areas' => ['مجال أ', 'مجال ب'], 'created_by' => null,
        ]);

        $this->assertSame(2, $this->postJson('/api/development-plans/seed', ['candidateId' => $c->id])
            ->assertOk()->json('created'));
        // الثانية لا تُنشئ شيئاً
        $this->assertSame(0, $this->postJson('/api/development-plans/seed', ['candidateId' => $c->id])
            ->assertOk()->json('created'));
        $this->assertSame(2, DevelopmentPlanItem::where('candidate_id', $c->id)->count());
    }

    // ── Fix 3: سجل التدقيق يحجب تفاصيل صف كيان مرتبط بمشارك عن غير المصرَّح له ──
    public function test_audit_log_redacts_candidate_linked_sibling_rows_for_uncleared_auditor(): void
    {
        $auditor = $this->actingAsRole('CENTER_MANAGER'); // AUDIT_VIEW بالدور
        // نسحب رؤية المصنّفين بالاستثناء — الحالة التي تستهدفها آلية الحجب
        $auditor->permissionOverrides()->create(['permission' => 'candidate.view_classified', 'granted' => false]);

        [$classified] = $this->makeCandidate(['sectorCode' => 'DW', 'classification' => 'secret']);
        AuditLog::create([
            'user_id' => $auditor->id, 'action' => 'CREATE_DEV_ITEM',
            'entity_type' => 'development_plan', 'entity_id' => '4242',
            'details' => ['candidate' => $classified->participant_code],
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        $entries = $this->getJson('/api/audit/log')->assertOk()->json('entries');
        $row = collect($entries)->firstWhere('actionCode', 'CREATE_DEV_ITEM');
        $this->assertNotNull($row);
        $this->assertTrue($row['redacted']);
        $this->assertNull($row['details']);
    }

    // ── Fix 3: الدور المصرَّح له يرى تفاصيل صف الكيان المرتبط ──
    public function test_audit_log_shows_sibling_details_to_cleared_auditor(): void
    {
        $auditor = $this->actingAsRole('CENTER_MANAGER'); // AUDIT_VIEW + view_classified بالدور
        [$c] = $this->makeCandidate(['sectorCode' => 'DW', 'classification' => 'secret']);
        AuditLog::create([
            'user_id' => $auditor->id, 'action' => 'CREATE_DEV_ITEM',
            'entity_type' => 'development_plan', 'entity_id' => '4243',
            'details' => ['candidate' => $c->participant_code],
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        $row = collect($this->getJson('/api/audit/log')->assertOk()->json('entries'))
            ->firstWhere('actionCode', 'CREATE_DEV_ITEM');
        $this->assertFalse($row['redacted']);
        $this->assertSame($c->participant_code, $row['details']['candidate']);
    }

    // ── Fix 3: صفّ المشارك «العادي» المباشر يبقى مرئياً لغير المصرَّح له (لا إفراط بالحجب) ──
    public function test_audit_log_keeps_normal_candidate_rows_visible(): void
    {
        $auditor = $this->actingAsRole('CENTER_MANAGER');
        $auditor->permissionOverrides()->create(['permission' => 'candidate.view_classified', 'granted' => false]);

        [$normal] = $this->makeCandidate(['sectorCode' => 'DW', 'classification' => 'normal']);
        AuditLog::create([
            'user_id' => $auditor->id, 'action' => 'UPDATE_CANDIDATE',
            'entity_type' => 'candidate', 'entity_id' => (string) $normal->id,
            'details' => ['field' => 'rank'], 'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        $row = collect($this->getJson('/api/audit/log')->assertOk()->json('entries'))
            ->firstWhere('entityId', (string) $normal->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['redacted']);
    }
}
