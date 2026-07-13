<?php

namespace Tests\Feature;

use App\Models\FinalReport;
use App\Models\MeasurementResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// وحدة أدوات القياس: رفع/عرض النتائج، بوابة الصلاحية/التصنيف، upsert لكل دورة، ودمجها بالمستند
class MeasurementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_view_requires_measurement_view(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('EXTERNAL_ADD'); // لا MEASUREMENT_VIEW
        $this->getJson("/api/measurements/{$c->id}")->assertStatus(403);
    }

    public function test_upload_requires_measurement_upload(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('CENTER_MANAGER'); // MEASUREMENT_VIEW فقط، لا UPLOAD
        $this->postJson('/api/measurements', [
            'candidateId' => $c->id, 'personalityScore' => 80,
        ])->assertStatus(403);
    }

    public function test_upload_then_view_roundtrip(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('MEASURE_SUPER'); // VIEW + UPLOAD

        $this->postJson('/api/measurements', [
            'candidateId' => $c->id, 'personalityScore' => 80, 'analyticalScore' => 70, 'englishScore' => 90,
        ])->assertOk();

        $this->assertSame($a->id, MeasurementResult::first()->assessment_id); // مربوطة بالدورة

        $res = $this->getJson("/api/measurements/{$c->id}")->assertOk();
        $this->assertEquals(80, $res->json('measurement.personalityScore'));
        $this->assertEquals(70, $res->json('measurement.analyticalScore'));
        $this->assertEquals(90, $res->json('measurement.englishScore'));
    }

    public function test_upload_is_upsert_per_cycle(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled']);
        $this->actingAsRole('MEASURE_SUPER');
        $this->postJson('/api/measurements', ['candidateId' => $c->id, 'personalityScore' => 50])->assertOk();
        $this->postJson('/api/measurements', ['candidateId' => $c->id, 'personalityScore' => 88])->assertOk();

        $this->assertSame(1, MeasurementResult::count());          // صف واحد لكل دورة
        $this->assertEquals(88, MeasurementResult::first()->personality_score); // مُحدّث
    }

    public function test_cannot_upload_for_completed_cycle(): void
    {
        [$c] = $this->makeCandidate(['status' => 'completed', 'assessmentStatus' => 'completed']);
        $this->actingAsRole('MEASURE_SUPER');
        $this->postJson('/api/measurements', ['candidateId' => $c->id, 'personalityScore' => 80])
            ->assertStatus(422);
    }

    public function test_classified_candidate_is_404_without_clearance(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'classification' => 'secret']);
        $this->actingAsRole('MEASURE_SUPER'); // لا VIEW_CLASSIFIED
        $this->postJson('/api/measurements', ['candidateId' => $c->id, 'personalityScore' => 80])
            ->assertStatus(404);
    }

    public function test_official_document_includes_measurement(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'assessmentStatus' => 'assessed']);
        $report = FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'recommendation' => 'مرشّح',
            'status' => 'approved', 'created_by' => null,
        ]);
        MeasurementResult::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'personality_score' => 82, 'analytical_score' => 77, 'english_score' => 91,
        ]);

        $this->actingAsRole('ASSESS_MANAGER');
        $html = $this->get("/api/reports/{$report->id}/document")->assertOk()->getContent();
        $this->assertStringContainsString('أدوات القياس', $html);
        $this->assertStringContainsString('القدرات التحليلية', $html);
    }
}
