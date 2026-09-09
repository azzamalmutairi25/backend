<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Evaluation;
use App\Models\MeasurementResult;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  محطّات الدورة — والاكتمال يُقاس على ما اختير لا على ثلاثٍ محفورة.
//
//  كان أوّلُ تقييمٍ يُرسَل يقلب المشارك إلى «تمّ تقييمه» فيُفتح بابُ التقرير:
//  من أدّى المقابلة وحدها كُتب تقريرُه على ثلثِ صورة. والاكتمال الآن على ما
//  اختير له وحده، فمن طُلب له تقييمٌ جزئيّ لا يبقى ناقصاً للأبد.
// ════════════════════════════════════════════════════════════
class AssessmentStationsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private ?User $evaluator = null;

    private function submitted(Assessment $a, string $activity, string $status = 'submitted'): Evaluation
    {
        // التقييم لا يقوم بلا مقيّم — القيد في القاعدة، ويُحترم هنا كما هناك
        $this->evaluator ??= $this->actingAsRole('EVALUATOR', 'DW');

        return Evaluation::create([
            'candidate_id' => $a->candidate_id,
            'assessment_id' => $a->id,
            'evaluator_id' => $this->evaluator->id,
            'activity' => $activity,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    // ═══ الافتراضي ═══

    public function test_every_new_assessment_is_born_with_the_three_stations(): void
    {
        [, $a] = $this->makeCandidate();

        $this->assertSame(['interview', 'discussion', 'measurement'], $a->chosenStations(),
            'الافتراضي الثلاث — والافتراض في النموذج لا في ستّة متحكّمات');
    }

    // ═══ الاكتمال ═══

    public function test_one_finished_station_out_of_three_is_not_completion(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->submitted($a, 'interview');

        $this->assertFalse($a->stationsComplete());
        $this->assertSame(['discussion', 'measurement'], $a->missingStations());
        $this->assertFalse($a->syncAssessedStatus());
        $this->assertSame('scheduled', $c->fresh()->status, 'لا يُفتح بابُ التقرير على ثلثِ صورة');
    }

    public function test_the_last_station_flips_the_participant(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->submitted($a, 'interview');
        $this->submitted($a, 'discussion');
        MeasurementResult::create(['candidate_id' => $c->id, 'assessment_id' => $a->id,
            'personality_score' => 80]);

        $a->unsetRelation('stations');
        $this->assertTrue($a->stationsComplete());
        $this->assertTrue($a->syncAssessedStatus());
        $this->assertSame('assessed', $c->fresh()->status);
    }

    // ── أدوات القياس محطّةٌ بلا تقييم ──
    // نتيجتها في `measurement_results` ولا تُكتب لها `evaluation` قطّ.
    // فقياسُ الاكتمال من التقييمات وحدها يجعلها مستحيلةً على الدوام.
    public function test_measurement_completes_from_its_result_not_from_an_evaluation(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);

        $this->assertNotContains('measurement', $a->completedStations());
        MeasurementResult::create(['candidate_id' => $c->id, 'assessment_id' => $a->id,
            'analytical_score' => 70]);

        $this->assertContains('measurement', $a->completedStations());
        $this->assertSame(0, Evaluation::where('activity', 'measurement')->count());
    }

    // ── والتقييم المعتمَد أتمُّ من المُرسَل ──
    // يمضي draft ← submitted ← approved، فحصرُه في الوسط يُسقط كلَّ ما
    // اعتمده المدير — وهي حال أغلب ما في القاعدة بعد أسبوع.
    public function test_an_approved_evaluation_counts_as_done(): void
    {
        [, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->submitted($a, 'interview', 'approved');

        $this->assertContains('interview', $a->completedStations());
    }

    public function test_a_draft_evaluation_does_not_count(): void
    {
        [, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->submitted($a, 'interview', 'draft');

        $this->assertNotContains('interview', $a->completedStations());
    }

    // ═══ التقييم الجزئي ═══

    public function test_a_partial_assessment_completes_on_what_was_chosen(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/assessments/{$a->id}/stations", ['stations' => ['interview']])
            ->assertOk()->assertJsonPath('complete', false);

        $this->submitted($a, 'interview');
        $a->unsetRelation('stations');

        $this->assertTrue($a->stationsComplete(), 'من طُلب له جزءٌ لا يبقى ناقصاً للأبد');
        $this->assertTrue($a->syncAssessedStatus());
        $this->assertSame('assessed', $c->fresh()->status);
    }

    // تقليصُ القائمة قد يُكمل الدورة — والحالة تتبع المحطّات لا العكس
    public function test_narrowing_the_list_can_complete_the_cycle_at_once(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->submitted($a, 'interview');

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/assessments/{$a->id}/stations", ['stations' => ['interview']])
            ->assertOk()->assertJsonPath('completed', true);

        $this->assertSame('assessed', $c->fresh()->status);
    }

    // ═══ الحراسات ═══

    public function test_a_cycle_is_never_left_without_a_station(): void
    {
        [, $a] = $this->makeCandidate();

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/assessments/{$a->id}/stations", ['stations' => []])
            ->assertStatus(422);

        $this->assertNotEmpty($a->fresh()->chosenStations(), 'دورةٌ بلا محطّة تكتمل لحظة إنشائها');
    }

    public function test_a_finished_station_is_not_removed(): void
    {
        [, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->submitted($a, 'discussion');

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/assessments/{$a->id}/stations", ['stations' => ['interview']])
            ->assertStatus(422)
            ->assertJsonPath('errors.stations.0', 'لا تُنزَع محطّةٌ أُنجزت: حلقة النقاش');

        $this->assertContains('discussion', $a->fresh()->chosenStations());
    }

    public function test_the_station_vocabulary_is_guarded_in_the_database(): void
    {
        [, $a] = $this->makeCandidate();

        $this->expectException(QueryException::class);
        DB::table('assessment_stations')->insert([
            'assessment_id' => $a->id, 'station' => 'workshop', 'sort_order' => 9,
        ]);
    }

    public function test_the_order_of_passage_is_kept_as_sent(): void
    {
        [, $a] = $this->makeCandidate();

        $this->actingAsRole('SCHEDULER');
        $res = $this->putJson("/api/assessments/{$a->id}/stations", [
            'stations' => ['measurement', 'interview', 'discussion'],
        ])->assertOk();

        $this->assertSame(['measurement', 'interview', 'discussion'],
            collect($res->json('stations'))->pluck('key')->all(),
            'الترتيب يختاره الاستقبال — ولا ترتيب محفور');
    }

    public function test_reading_needs_view_and_writing_needs_manage(): void
    {
        [, $a] = $this->makeCandidate();

        $this->actingAsRole('OPERATIONS');
        $this->getJson("/api/assessments/{$a->id}/stations")->assertOk();

        $this->actingAsRole('EVALUATOR', 'DW');
        $this->getJson("/api/assessments/{$a->id}/stations")->assertStatus(403);
        $this->putJson("/api/assessments/{$a->id}/stations", ['stations' => ['interview']])
            ->assertStatus(403);
    }

    // ═══ الأثر على الاستقبال ═══

    public function test_the_reception_roster_names_the_missing_station(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->giveCv($c);
        $this->submitted($a, 'interview');

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])->assertStatus(201);

        $row = collect($this->getJson('/api/reception')->json('visits'))->firstWhere('candidateId', $c->id);

        $this->assertSame(['حلقة النقاش', 'أدوات القياس'], $row['missingStations'],
            'الناقص يُسمّى — «٢ من ٣» لا تُقرأ');
        $this->assertTrue(collect($row['stations'])->firstWhere('key', 'interview')['done']);
    }
}
