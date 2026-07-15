<?php

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// نطاق التقارير: القطاع حدّ أعلى لكل محصور، والمقيّم يضيق داخله لمن قيّمهم هو.
class ReportScopeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** يصنع تقريراً، ويسند تقييمه للمقيّم المعطى إن وُجد */
    private function reportFor(string $sectorCode, ?User $evaluatedBy = null): FinalReport
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'sectorCode' => $sectorCode]);

        if ($evaluatedBy) {
            Evaluation::create([
                'candidate_id' => $c->id, 'assessment_id' => $a->id,
                'evaluator_id' => $evaluatedBy->id, 'activity' => 'interview',
                'status' => 'submitted', 'submitted_at' => now(),
            ]);
        }

        return FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'recommendation' => 'يوصى به', 'status' => 'pending_evaluator', 'created_by' => null,
        ]);
    }

    private function listIds(): array
    {
        return array_column($this->getJson('/api/reports')->assertOk()->json('reports'), 'id');
    }

    // ── المقيّم: قطاعه + من قيّمهم ──

    public function test_evaluator_sees_only_reports_of_candidates_they_evaluated(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        $mine = $this->reportFor('ED', $ev);
        $sameSectorOther = $this->reportFor('ED');          // قطاعه لكن لم يقيّمه
        $otherSector = $this->reportFor('HO', $ev);         // قيّمه لكن خارج قطاعه

        \Laravel\Sanctum\Sanctum::actingAs($ev);
        $ids = $this->listIds();

        $this->assertContains($mine->id, $ids, 'قطاعه وقيّمه');
        $this->assertNotContains($sameSectorOther->id, $ids, 'قطاعه لكن لم يقيّمه');
        $this->assertNotContains($otherSector->id, $ids, 'قيّمه لكن خارج قطاعه');
        $this->assertCount(1, $ids);
    }

    // مستشار حلقة النقاش لا يملك report.view أصلاً — النطاق لا يُبلَغ عنده
    public function test_discussion_evaluator_cannot_see_reports_at_all(): void
    {
        $de = $this->actingAsRole('DISCUSSION_EVAL', 'ED');
        $this->reportFor('ED', $de);

        \Laravel\Sanctum\Sanctum::actingAs($de);
        $this->getJson('/api/reports')->assertStatus(403);
    }

    // ── المساعد: قطاعه كاملاً ──

    public function test_assistant_sees_their_whole_sector_not_just_what_they_touched(): void
    {
        $as = $this->actingAsRole('ASSISTANT', 'ED');
        $a1 = $this->reportFor('ED');
        $a2 = $this->reportFor('ED');
        $other = $this->reportFor('HO');

        \Laravel\Sanctum\Sanctum::actingAs($as);
        $ids = $this->listIds();

        // يكتب تقارير قطاعه، فلا يُحصر بمن قيّمهم — هو لا يقيّم أصلاً
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    // ── غير المحصور: الكل ──

    public function test_unbound_roles_see_every_report(): void
    {
        $this->reportFor('ED');
        $this->reportFor('HO');

        foreach (['ADMIN', 'ASSESS_MANAGER', 'DEV_MANAGER', 'CENTER_MANAGER'] as $role) {
            $this->actingAsRole($role);
            $this->assertCount(2, $this->listIds(), $role);
        }
    }

    // ── الإحصاء يطابق القائمة ──

    public function test_stats_match_the_list_the_user_can_see(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        $this->reportFor('ED', $ev);
        $this->reportFor('ED');   // قطاعه، لم يقيّمه
        $this->reportFor('HO');   // خارج قطاعه

        \Laravel\Sanctum\Sanctum::actingAs($ev);
        $stats = $this->getJson('/api/reports/stats')->assertOk()->json('stats');

        // الرقم لا يعدّ ما لا تعرضه القائمة
        $this->assertSame(1, $stats['pending']);
        $this->assertCount(1, $this->listIds());
    }

    // ── المسارات المفردة لا تلتفّ على القائمة ──

    public function test_show_is_404_for_a_report_outside_the_scope(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        $hidden = $this->reportFor('ED'); // قطاعه لكن لم يقيّمه

        \Laravel\Sanctum\Sanctum::actingAs($ev);
        // «غير موجود» لا «ليس لك» — المعرّف لا يكشف الوجود
        $this->getJson("/api/reports/{$hidden->id}")->assertStatus(404);
    }

    public function test_document_is_404_for_a_report_outside_the_scope(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        $hidden = $this->reportFor('HO');

        \Laravel\Sanctum\Sanctum::actingAs($ev);
        // المستند وثيقة كاملة — لا يكون أوسع من القائمة
        $this->getJson("/api/reports/{$hidden->id}/document")->assertStatus(404);
    }

    public function test_show_works_inside_the_scope(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'ED');
        $mine = $this->reportFor('ED', $ev);

        \Laravel\Sanctum\Sanctum::actingAs($ev);
        $this->getJson("/api/reports/{$mine->id}")->assertOk()->assertJsonPath('report.id', $mine->id);
    }

    // ── قائمة «جاهزون للتقرير» محصورة كذلك ──

    public function test_eligible_candidates_is_limited_to_the_users_sector(): void
    {
        $this->makeCandidate(['status' => 'assessed', 'sectorCode' => 'ED']);
        $this->makeCandidate(['status' => 'assessed', 'sectorCode' => 'HO']);
        $this->actingAsRole('ASSISTANT', 'ED');

        $rows = $this->getJson('/api/reports/eligible-candidates')->assertOk()->json('candidates');
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertSame('التعليم', $r['sectorName']);
        }
    }

    // ── التصدير ──
    // report.export عند غير المحصورين وحدهم (مدير النظام/المركز/التقييم/تطوير
    // الكفاءات)، فحصرُه لا أثر له عملياً اليوم — لكنه مكتوب لئلا يصير ثغرةً
    // لحظةَ مُنحت الصلاحية لدور محصور.
    public function test_evaluator_cannot_export_at_all(): void
    {
        $this->actingAsRole('EVALUATOR', 'ED');
        $this->get('/api/reports/export')->assertStatus(403);
    }

    public function test_export_covers_everything_for_an_unbound_exporter(): void
    {
        $a = $this->reportFor('ED');
        $b = $this->reportFor('HO');
        $this->actingAsRole('ASSESS_MANAGER');

        $csv = $this->get('/api/reports/export')->assertOk()->getContent();
        $this->assertStringContainsString($a->candidate->participant_code, $csv);
        $this->assertStringContainsString($b->candidate->participant_code, $csv);
    }
}
