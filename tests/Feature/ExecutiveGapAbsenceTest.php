<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// المؤشرات التنفيذية — الفجوة عن مستهدف الرتبة، والغياب لكل قطاع
class ExecutiveGapAbsenceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(): User
    {
        return User::create([
            'username' => 'ev_'.substr(md5(uniqid('', true)), 0, 6), 'full_name' => 'مقيّم',
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', 'DW')->value('id'), 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    private function scored(int $competencyId, int $score, ?int $rankId): void
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'assessed']);
        if ($rankId) {
            DB::table('candidates')->where('id', $c->id)->update(['rank_id' => $rankId]);
        }
        $e = Evaluation::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'evaluator_id' => $this->evaluator()->id,
            'activity' => 'interview', 'status' => 'approved',
        ]);
        EvaluationScore::create(['evaluation_id' => $e->id, 'competency_id' => $competencyId, 'score' => $score]);
    }

    public function test_gap_is_the_average_score_minus_the_rank_target(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        // صفٌّ من المصفوفة المعتمدة نفسها — مستهدفه دون الخمسة كي تتّسع درجةٌ فوقه
        $t = DB::table('rank_competency_targets')->where('target', '<=', 4)->orderBy('id')->first();
        $this->assertNotNull($t, 'مصفوفة المستهدفات غير مبذورة');

        $this->scored($t->competency_id, $t->target + 1, $t->rank_id);   // فوق المستهدف بمستوى
        $this->scored($t->competency_id, 1, null);                        // بلا رتبة: في الإتقان لا الفجوة

        $gap = $this->getJson('/api/analytics/executive')->assertOk()->json('heatmapGap');
        $key = $t->competency_id.'-'.Sector::where('code', 'DW')->value('id');

        $this->assertEquals(1, $gap['cells'][$key]['gap']);
        $this->assertSame(1, $gap['cells'][$key]['samples']);
        $this->assertSame(['withTarget' => 1, 'total' => 2], $gap['coverage']);
    }

    public function test_sector_comparison_carries_the_absence_rate_of_recorded_sessions(): void
    {
        $this->actingAsRole('CENTER_MANAGER');

        foreach (['present', 'present', 'present', 'absent_unexcused', null] as $status) {
            [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled']);
            $s = Schedule::create([
                'candidate_id' => $c->id, 'assessment_id' => $a->id,
                'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00:00',
                'activity' => 'interview', 'location' => 'قاعة',
            ]);
            if ($status) {
                Attendance::create([
                    'schedule_id' => $s->id, 'status' => $status,
                    'check_in_time' => $status === 'present' ? now() : null, 'recorded_by' => null,
                ]);
            }
        }

        $row = collect($this->getJson('/api/analytics/executive')->assertOk()->json('sectorComparison'))
            ->firstWhere('sectorId', Sector::where('code', 'DW')->value('id'));

        // ثلاثة حضروا وغاب واحد؛ الجلسة غير المرصودة خارج المقام
        $this->assertEquals(25, $row['absenceRate']);
    }
}
