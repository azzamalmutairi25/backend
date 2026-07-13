<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// المخرجات: المستند الرسمي (HTML للطباعة) وتصدير CSV
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function report(array $attrs = []): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $report = FinalReport::create(array_merge([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح قوي',
            'behavioral_fit' => 82.5, 'technical_fit' => 74, 'status' => 'approved',
            'strengths' => ['قيادة', 'تواصل'], 'development_areas' => ['التفويض'], 'created_by' => null,
        ], $attrs));
        return [$c, $a, $report];
    }

    public function test_document_renders_official_html(): void
    {
        [$c, , $report] = $this->report();
        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_VIEW + VIEW_NAMES

        $res = $this->get("/api/reports/{$report->id}/document")->assertOk();
        $res->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $html = $res->getContent();
        $this->assertStringContainsString('مركز كفاءات', $html);
        $this->assertStringContainsString($c->participant_code, $html);
        $this->assertStringContainsString('التوصية', $html);
        $this->assertStringContainsString('مرشّح قوي', $html);
        $this->assertStringContainsString('التفويض', $html); // مجال تطوير
    }

    public function test_document_is_404_for_classified_without_clearance(): void
    {
        [$c, , $report] = $this->report();
        $c->update(['classification' => 'secret']);

        // CENTER_MANAGER يملك REPORT_VIEW لكن لا VIEW_CLASSIFIED → مصنّف = «غير موجود»
        $this->actingAsRole('CENTER_MANAGER');
        $this->get("/api/reports/{$report->id}/document")->assertStatus(404);
    }

    public function test_document_requires_report_view(): void
    {
        [, , $report] = $this->report();
        $this->actingAsRole('EXTERNAL_ADD'); // لا REPORT_VIEW
        $this->get("/api/reports/{$report->id}/document")->assertStatus(403);
    }

    public function test_csv_export_has_bom_and_rows(): void
    {
        [$c] = $this->report();
        $this->actingAsRole('ASSESS_MANAGER'); // REPORT_EXPORT

        $res = $this->get('/api/reports/export')->assertOk();
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $res->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body); // BOM لـ Excel
        $this->assertStringContainsString('الرمز,القطاع', $body);
        $this->assertStringContainsString($c->participant_code, $body);
        $this->assertStringContainsString('معتمد', $body);
    }

    public function test_csv_export_requires_report_export(): void
    {
        $this->actingAsRole('EVALUATOR'); // REPORT_VIEW لكن لا REPORT_EXPORT
        $this->get('/api/reports/export')->assertStatus(403);
    }
}
