<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PeriodAssessor;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// موجة الجدولة: تواريخها، ولوحة أسمائها ونصابها، ومسار اعتماد مدير المركز.
//
// الجدولة يدوية — الموجة تُعلن المدى وتُرشِّح الأسماء، والجلسات تُبنى بيد
// المُجدوِل من شاشة الجدولة. لذلك الاختبارات تركّز على ثلاثة: أن المدى يُحترم،
// وأن النصاب يُقرأ ولا يمنع، وأن من يبني لا يعتمد.
class SchedulingPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function person(string $roleCode, string $sectorCode = 'DW', bool $active = true): User
    {
        return User::create([
            'username' => 'u_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستخدم ' . $roleCode,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => $active,
            'must_change_password' => false,
        ]);
    }

    private function makePeriod(array $over = []): SchedulingPeriod
    {
        return SchedulingPeriod::create(array_merge([
            'name' => 'دورة الاختبار ' . uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'draft',
        ], $over));
    }

    // ── التواريخ ──

    public function test_scheduler_creates_a_three_day_period_and_days_returns_three(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->postJson('/api/scheduling-periods', [
            'name' => 'دورة أغسطس ١',
            'startDate' => now()->addDay()->toDateString(),
            'endDate' => now()->addDays(3)->toDateString(),
        ])->assertStatus(201);

        $this->assertSame(3, $res->json('period.dayCount'));
        $this->assertSame('draft', $res->json('period.status'));
        $this->assertCount(3, SchedulingPeriod::first()->days());
    }

    public function test_end_before_start_is_rejected(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/scheduling-periods', [
            'name' => 'مقلوبة',
            'startDate' => now()->addDays(5)->toDateString(),
            'endDate' => now()->addDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_duplicate_name_is_409_not_500(): void
    {
        $this->actingAsRole('SCHEDULER');
        $payload = [
            'name' => 'دورة مكرّرة',
            'startDate' => now()->addDay()->toDateString(),
            'endDate' => now()->addDays(2)->toDateString(),
        ];
        $this->postJson('/api/scheduling-periods', $payload)->assertStatus(201);
        $this->postJson('/api/scheduling-periods', $payload)->assertStatus(409);
    }

    public function test_session_times_fall_back_to_the_global_setting(): void
    {
        $period = $this->makePeriod();
        $this->assertSame(['10:15', '12:30', '14:30'], $period->sessionTimes());

        $period->session_times = '09:00,11:00';
        $this->assertSame(['09:00', '11:00'], $period->sessionTimes());
    }

    public function test_malformed_session_times_are_rejected(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/scheduling-periods', [
            'name' => 'أوقات خاطئة',
            'startDate' => now()->addDay()->toDateString(),
            'endDate' => now()->addDays(2)->toDateString(),
            'sessionTimes' => '10:15,99:99',
        ])->assertStatus(422);
    }

    // ── لوحة الأسماء والنصاب ──

    public function test_panel_stores_per_person_quota_and_falls_back_to_the_global_cap(): void
    {
        $period = $this->makePeriod();
        $withQuota = $this->person('EVALUATOR');
        $withoutQuota = $this->person('EVALUATOR');

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [
                ['userId' => $withQuota->id, 'activity' => 'interview', 'seat' => 'evaluator', 'dailyQuota' => 4],
                ['userId' => $withoutQuota->id, 'activity' => 'interview', 'seat' => 'evaluator'],
            ],
        ])->assertOk()->assertJsonPath('saved', 2);

        $a = PeriodAssessor::where('user_id', $withQuota->id)->first();
        $b = PeriodAssessor::where('user_id', $withoutQuota->id)->first();
        $this->assertSame(4, $a->dailyQuota(), 'النصاب الخاص يُقرأ كما هو');
        $this->assertSame(5, $b->dailyQuota(), 'من بلا نصاب يقع على الإعداد العام');
    }

    public function test_assistants_are_finally_reachable_through_the_panel(): void
    {
        $period = $this->makePeriod();
        $assistant = $this->person('ASSISTANT');

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $assistant->id, 'activity' => 'interview', 'seat' => 'assistant']],
        ])->assertOk()->assertJsonPath('saved', 1);

        $this->assertDatabaseHas('period_assessors', [
            'user_id' => $assistant->id, 'seat' => 'assistant', 'activity' => 'interview',
        ]);
    }

    public function test_a_role_that_does_not_fit_the_seat_is_rejected_with_a_reason(): void
    {
        $period = $this->makePeriod();
        $evaluator = $this->person('EVALUATOR');

        $this->actingAsRole('SCHEDULER');
        // مقيّم المقابلة ليس مستشار حلقة نقاش — الدور لا يؤهّله
        $res = $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $evaluator->id, 'activity' => 'discussion', 'seat' => 'evaluator']],
        ])->assertOk();

        $this->assertSame(0, $res->json('saved'));
        $this->assertCount(1, $res->json('rejected'));
        $this->assertDatabaseCount('period_assessors', 0);
    }

    public function test_saving_the_panel_replaces_it_wholly(): void
    {
        $period = $this->makePeriod();
        $first = $this->person('EVALUATOR');
        $second = $this->person('EVALUATOR');

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $first->id, 'activity' => 'interview', 'seat' => 'evaluator']],
        ])->assertOk();
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $second->id, 'activity' => 'interview', 'seat' => 'evaluator']],
        ])->assertOk();

        $this->assertDatabaseCount('period_assessors', 1);
        $this->assertDatabaseHas('period_assessors', ['user_id' => $second->id]);
    }

    public function test_eligible_list_is_scoped_by_activity_and_seat(): void
    {
        $period = $this->makePeriod();
        $interviewer = $this->person('EVALUATOR');
        $discussion = $this->person('DISCUSSION_EVAL');
        $assistant = $this->person('ASSISTANT');

        $this->actingAsRole('SCHEDULER');

        $ids = fn ($res) => collect($res->json('eligible'))->pluck('id')->all();

        $this->assertContains($interviewer->id, $ids(
            $this->getJson("/api/scheduling-periods/{$period->id}/eligible?activity=interview&seat=evaluator")->assertOk()
        ));
        $this->assertContains($discussion->id, $ids(
            $this->getJson("/api/scheduling-periods/{$period->id}/eligible?activity=discussion&seat=evaluator")->assertOk()
        ));
        $this->assertSame([$assistant->id], $ids(
            $this->getJson("/api/scheduling-periods/{$period->id}/eligible?activity=interview&seat=assistant")->assertOk()
        ));
    }

    // ── الجلسات والمدى ──

    public function test_a_session_can_be_attached_to_a_period(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id,
            'activity' => 'interview',
            'date' => $period->start_date->toDateString(),
            'time' => '10:15',
            'periodId' => $period->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('schedules', ['candidate_id' => $c->id, 'period_id' => $period->id]);
    }

    public function test_a_date_outside_the_period_range_is_refused(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id,
            'activity' => 'interview',
            'date' => now()->addDays(20)->toDateString(),   // خارج المدى
            'time' => '10:15',
            'periodId' => $period->id,
        ])->assertStatus(422);
    }

    public function test_a_session_without_a_period_still_works_exactly_as_before(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id,
            'activity' => 'interview',
            'date' => now()->addDay()->toDateString(),
            'time' => '10:15',
        ])->assertStatus(201);

        $this->assertDatabaseHas('schedules', ['candidate_id' => $c->id, 'period_id' => null]);
    }

    public function test_narrowing_the_range_over_existing_sessions_is_refused(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $period->end_date->toDateString(), 'time' => '10:15', 'periodId' => $period->id,
        ])->assertStatus(201);

        $this->putJson("/api/scheduling-periods/{$period->id}", [
            'name' => $period->name,
            'startDate' => $period->start_date->toDateString(),
            'endDate' => $period->start_date->toDateString(),   // يستثني الجلسة
        ])->assertStatus(422);
    }

    // ── النصاب عدّاد لا سدّ ──

    public function test_quota_is_a_counter_not_a_gate(): void
    {
        $period = $this->makePeriod();
        $evaluator = $this->person('EVALUATOR');

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $evaluator->id, 'activity' => 'interview', 'seat' => 'evaluator', 'dailyQuota' => 1]],
        ])->assertOk();

        // جلستان في اليوم نفسه لنفس المقيّم رغم نصابٍ قدره ١ — تمرّان
        foreach (['10:15', '12:30'] as $time) {
            [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
            $this->postJson('/api/schedules', [
                'candidateId' => $c->id, 'activity' => 'interview',
                'date' => $period->start_date->toDateString(), 'time' => $time,
                'evaluatorId' => $evaluator->id, 'periodId' => $period->id,
            ])->assertStatus(201);
        }

        $res = $this->getJson("/api/scheduling-periods/{$period->id}/assessors")->assertOk();
        $row = collect($res->json('assessors'))->firstWhere('userId', $evaluator->id);
        $this->assertSame(2, $row['assigned'], 'الحمل يُعرض كما هو');
        $this->assertSame(1, $row['dailyQuota'], 'والنصاب المعلن بجانبه — للقراءة لا للمنع');
    }

    public function test_assessors_endpoint_returns_quota_and_load_for_the_period(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();
        $onPanel = $this->person('EVALUATOR');
        $offPanel = $this->person('EVALUATOR');

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $onPanel->id, 'activity' => 'interview', 'seat' => 'evaluator', 'dailyQuota' => 3]],
        ])->assertOk();

        $res = $this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator&periodId={$period->id}")
            ->assertOk();

        $rows = collect($res->json('assessors'));
        $this->assertSame($onPanel->id, $rows->first()['id'], 'المُدرَج في اللوحة يتقدّم');
        $this->assertTrue($rows->firstWhere('id', $onPanel->id)['onPanel']);
        $this->assertSame(3, $rows->firstWhere('id', $onPanel->id)['dailyQuota']);
        // من ليس في اللوحة يبقى قابلاً للاختيار — اللوحة ترتيب لا حصر
        $this->assertNotNull($rows->firstWhere('id', $offPanel->id));
    }

    public function test_the_legacy_interviewers_route_still_answers(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->person('EVALUATOR');

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson("/api/candidates/{$c->id}/interviewers")->assertOk();
        $this->assertSame([$ev->id], collect($res->json('interviewers'))->pluck('id')->all());
        $this->assertFalse($res->json('hasCv'));
    }

    // ── فصل المهام: من يبني لا يعتمد ──

    public function test_scheduler_cannot_approve_but_center_manager_can(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '10:15', 'periodId' => $period->id,
        ])->assertStatus(201);

        $this->postJson("/api/scheduling-periods/{$period->id}/submit")->assertOk();
        $this->assertSame('pending_center', $period->fresh()->status);

        // الباني لا يعتمد
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertStatus(403);

        $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertOk();
        $this->assertSame('approved', $period->fresh()->status);
    }

    public function test_submitting_an_empty_period_is_refused(): void
    {
        $period = $this->makePeriod();
        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/submit")->assertStatus(422);
    }

    public function test_submit_notifies_the_approvers_and_not_the_submitter(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $manager = $this->person('CENTER_MANAGER');
        $submitter = $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '10:15', 'periodId' => $period->id,
        ])->assertStatus(201);
        $this->postJson("/api/scheduling-periods/{$period->id}/submit")->assertOk();

        $this->assertTrue(
            Notification::where('recipient_id', $manager->id)
                ->where('entity_type', 'scheduling_period')->exists(),
            'مدير المركز يُشعَر'
        );
        $this->assertFalse(
            Notification::where('recipient_id', $submitter->id ?? 0)
                ->where('entity_type', 'scheduling_period')->exists(),
            'ولا يُشعَر من أرسلها بفعل نفسه'
        );
    }

    public function test_reject_needs_a_reason_and_returns_it_to_draft(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '10:15', 'periodId' => $period->id,
        ])->assertStatus(201);
        $this->postJson("/api/scheduling-periods/{$period->id}/submit")->assertOk();

        $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/scheduling-periods/{$period->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/scheduling-periods/{$period->id}/reject", ['reason' => 'ينقص مقيّم'])->assertOk();

        $fresh = $period->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertSame('ينقص مقيّم', $fresh->reject_reason);
    }

    public function test_an_approved_period_refuses_new_sessions_and_panel_edits(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod(['status' => 'approved']);
        $ev = $this->person('EVALUATOR');

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '10:15', 'periodId' => $period->id,
        ])->assertStatus(422);

        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $ev->id, 'activity' => 'interview', 'seat' => 'evaluator']],
        ])->assertStatus(422);
    }

    public function test_a_period_with_sessions_cannot_be_deleted(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '10:15', 'periodId' => $period->id,
        ])->assertStatus(201);

        $this->deleteJson("/api/scheduling-periods/{$period->id}")->assertStatus(422);
    }

    public function test_listing_sessions_can_be_narrowed_to_a_period(): void
    {
        [$inWave] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$outside] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $inWave->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '10:15', 'periodId' => $period->id,
        ])->assertStatus(201);
        $this->postJson('/api/schedules', [
            'candidateId' => $outside->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '12:30',
        ])->assertStatus(201);

        $res = $this->getJson("/api/schedules?periodId={$period->id}")->assertOk();
        $codes = collect($res->json('schedules'))->pluck('candidateId')->all();
        $this->assertSame([$inWave->id], $codes);
    }

    public function test_a_viewer_without_manage_cannot_write(): void
    {
        $period = $this->makePeriod();
        $this->actingAsRole('EVALUATOR', 'DW');   // schedule.view فقط لا manage

        $this->postJson('/api/scheduling-periods', [
            'name' => 'محاولة', 'startDate' => now()->addDay()->toDateString(),
            'endDate' => now()->addDays(2)->toDateString(),
        ])->assertStatus(403);
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", ['rows' => []])->assertStatus(403);
        $this->postJson("/api/scheduling-periods/{$period->id}/submit")->assertStatus(403);
    }

    public function test_rescheduling_outside_the_wave_drops_the_period_link(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();

        $schedule = Schedule::create([
            'candidate_id' => $c->id,
            'assessment_id' => $c->assessments()->first()->id,
            'period_id' => $period->id,
            'schedule_date' => $period->start_date->toDateString(),
            'schedule_time' => '10:15',
            'activity' => 'interview',
        ]);
        $schedule->attendance()->create(['status' => 'absent_excused', 'recorded_by' => null]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/schedules/{$schedule->id}/reschedule", [
            'date' => now()->addDays(30)->toDateString(),   // بعد انتهاء الموجة
            'time' => '10:15',
        ])->assertStatus(201);

        $created = Schedule::where('candidate_id', $c->id)->where('id', '!=', $schedule->id)->first();
        $this->assertNull($created->period_id, 'جلسة خارج مدى الموجة لا تُنسب إليها');
    }
}
