<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// الملخّص التنفيذي: بصلاحية مدير المركز فقط، قابل للتفويض عبر الاستثناءات،
// ويظهر في المستند المختصر القابل للطباعة.
class ExecutiveSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function report(): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $r = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => 'pending_center',
            'recommendation' => 'يوصى به', 'created_by' => null,
        ]);
        return [$c, $r];
    }

    public function test_center_manager_writes_executive_summary(): void
    {
        [$c, $r] = $this->report();
        $user = $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/reports/{$r->id}/executive-summary", ['executiveSummary' => 'مرشّح جاهز للترقية'])
            ->assertOk();
        $r->refresh();
        $this->assertSame('مرشّح جاهز للترقية', $r->executive_summary);
        $this->assertSame($user->id, $r->exec_summary_by);
        $this->assertNotNull($r->exec_summary_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SAVE_EXEC_SUMMARY']);
    }

    public function test_others_cannot_write_executive_summary(): void
    {
        [$c, $r] = $this->report();
        $this->actingAsRole('ASSESS_MANAGER'); // لا REPORT_EXEC_SUMMARY
        $this->postJson("/api/reports/{$r->id}/executive-summary", ['executiveSummary' => 'x'])
            ->assertStatus(403);
    }

    public function test_permission_is_delegatable_via_overrides(): void
    {
        [$c, $r] = $this->report();
        // مدير التقييم مفوَّض صلاحية الملخّص التنفيذي عبر الاستثناءات
        $user = $this->actingAsRole('ASSESS_MANAGER');
        $user->permissionOverrides()->create(['permission' => Permissions::REPORT_EXEC_SUMMARY, 'granted' => true]);
        $this->postJson("/api/reports/{$r->id}/executive-summary", ['executiveSummary' => 'تفويض ناجح'])
            ->assertOk();
        $this->assertSame('تفويض ناجح', $r->fresh()->executive_summary);
    }

    public function test_show_exposes_executive_summary_and_edit_flag(): void
    {
        [$c, $r] = $this->report();
        $r->update(['executive_summary' => 'ملخّص']);
        $this->actingAsRole('CENTER_MANAGER');
        $res = $this->getJson("/api/reports/{$r->id}")->assertOk();
        $this->assertSame('ملخّص', $res->json('report.executiveSummary'));
        $this->assertTrue($res->json('report.canEditExecSummary'));

        $this->actingAsRole('ASSESS_MANAGER');
        $this->assertFalse($this->getJson("/api/reports/{$r->id}")->json('report.canEditExecSummary'));
    }

    public function test_brief_document_renders_summary_and_is_printable(): void
    {
        [$c, $r] = $this->report();
        $r->update(['executive_summary' => 'جاهزية عالية للقيادة', 'behavioral_fit' => 85]);
        $this->actingAsRole('CENTER_MANAGER'); // REPORT_VIEW + VIEW_NAMES
        $html = $this->get("/api/reports/{$r->id}/brief")->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')->getContent();
        $this->assertStringContainsString('الملخّص التنفيذي', $html);
        $this->assertStringContainsString('جاهزية عالية للقيادة', $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString('85%', $html);
    }

    public function test_brief_document_requires_report_view(): void
    {
        [$c, $r] = $this->report();
        $this->actingAsRole('EXTERNAL_ADD'); // لا REPORT_VIEW
        $this->get("/api/reports/{$r->id}/brief")->assertStatus(403);
    }

    public function test_executive_summary_is_404_out_of_scope(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'classification' => 'secret']);
        $r = FinalReport::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => 'pending_center', 'recommendation' => 'x', 'created_by' => null]);
        // مدير المركز لا يرى المصنّفين → خارج النطاق → 404 لا 403
        $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/reports/{$r->id}/executive-summary", ['executiveSummary' => 'x'])->assertStatus(404);
    }
}
