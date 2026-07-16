<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\DevelopmentPlanItem;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\FinalReport;
use App\Models\MeasurementResult;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// المستند المفصّل: الأقسام السبعة + الكفاءات مجمّعة (سلوكي حسب المجموعة،
// فنّي حسب المجال) + اللغة الإنجليزية + خطة التطوير الفردية.
class ReportDocumentSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_document_shows_seven_sections_grouped_comps_english_and_dev_plan(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed', 'rankLabel' => 'مدير عام']);

        // كفاءات: سلوكية بمجموعة، فنية بمجال
        $behExcel = Competency::create(['name_ar' => 'الحسّ القيادي', 'type' => 'behavioral', 'group' => 'الإحساس', 'max_level' => 5, 'weight' => 1, 'sort_order' => 60]);
        $techFin = Competency::create(['name_ar' => 'التحليل المالي', 'type' => 'technical', 'domain' => 'المالية', 'max_level' => 5, 'weight' => 1, 'sort_order' => 61]);

        $evUser = User::create(['username' => 'ev_' . substr(md5(uniqid('', true)), 0, 8), 'full_name' => 'مقيّم', 'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'EVALUATOR')->value('id'), 'sector_id' => Sector::where('code', 'ED')->value('id'), 'is_active' => true, 'must_change_password' => false]);
        $ev = Evaluation::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $evUser->id, 'activity' => 'interview', 'status' => 'submitted']);
        EvaluationScore::create(['evaluation_id' => $ev->id, 'competency_id' => $behExcel->id, 'score' => 4]);
        EvaluationScore::create(['evaluation_id' => $ev->id, 'competency_id' => $techFin->id, 'score' => 3]);

        MeasurementResult::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'english_score' => 88]);

        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => 'approved',
            'behavioral_fit' => 80, 'technical_fit' => 60, 'recommendation' => 'يوصى به',
            'overview_text' => 'أداء متميز', 'strengths' => ['القيادة'], 'development_areas' => ['التفويض'],
            'created_by' => null,
        ]);

        DevelopmentPlanItem::create(['candidate_id' => $c->id, 'assessment_id' => $a->id, 'area' => 'مهارات العرض', 'action' => 'دورة تدريبية', 'status' => 'pending']);

        $this->actingAsRole('CENTER_MANAGER'); // REPORT_VIEW + VIEW_NAMES
        $html = $this->get("/api/reports/{$report->id}/document")->assertOk()->getContent();

        // الأقسام السبعة
        $this->assertStringContainsString('البيانات الشخصية والوظيفية', $html);
        $this->assertStringContainsString('نتائج التقييم النهائية', $html);
        $this->assertStringContainsString('الكفاءات السلوكية', $html);
        $this->assertStringContainsString('الكفاءات الفنية حسب مجالات التقييم', $html);
        $this->assertStringContainsString('تقييم اللغة الإنجليزية', $html);
        $this->assertStringContainsString('المرئيات والتوصيات', $html);
        $this->assertStringContainsString('خطة التطوير الفردية', $html);
        // البيانات الوظيفية
        $this->assertStringContainsString('مدير عام', $html); // الرتبة/المسمى
        // التجميع
        $this->assertStringContainsString('الإحساس', $html);  // مجموعة سلوكية
        $this->assertStringContainsString('المالية', $html);  // مجال فنّي
        // الإنجليزية وخطة التطوير
        $this->assertStringContainsString('88 / 100', $html);
        $this->assertStringContainsString('مهارات العرض', $html);
        // توقيع مدير المركز أُضيف
        $this->assertStringContainsString('مدير المركز', $html);
    }

    public function test_document_handles_empty_sections_gracefully(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => 'draft',
            'recommendation' => 'قيد المراجعة', 'created_by' => null,
        ]);
        $this->actingAsRole('CENTER_MANAGER');
        $html = $this->get("/api/reports/{$report->id}/document")->assertOk()->getContent();
        $this->assertStringContainsString('لا توجد خطة تطوير مسجّلة', $html);
        $this->assertStringContainsString('لم تُسجَّل درجة اللغة الإنجليزية', $html);
    }
}
