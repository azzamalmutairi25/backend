<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\DiscussionCircle;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// حلقة النقاش ككيان: سعةٌ ومستشارٌ ومجموعةُ مشاركين.
//
// قراران تُركا إعداداً لا تخميناً: السعة رقمٌ افتراضي في الإعدادات تتجاوزه كل
// حلقة، والعلاقة بمجموعتَي الكشف (أ/ب) حرفٌ اختياري يُملأ أو يُترك.
class DiscussionCircleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function person(string $roleCode, string $sectorCode = 'DW'): User
    {
        return User::create([
            'username' => 'u_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستخدم ' . $roleCode,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'sectorId' => Sector::where('code', 'DW')->value('id'),
            'date' => now()->addDay()->toDateString(),
            'time' => '12:30',
        ], $over);
    }

    // ── السعة إعدادٌ لا رقمٌ محفور ──

    public function test_capacity_defaults_to_the_setting_and_is_overridable(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        $this->actingAsRole('SCHEDULER');

        $res = $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id]))
            ->assertStatus(201);
        $this->assertSame(6, $res->json('circle.capacity'), 'الافتراضي من الإعدادات');

        Setting::updateOrCreate(['key' => 'discussion.default_circle_capacity'], ['value' => '9']);
        $res = $this->postJson('/api/discussion-circles', $this->payload(['time' => '14:30']))
            ->assertStatus(201);
        $this->assertSame(9, $res->json('circle.capacity'), 'تغيير الإعداد يسري على الجديد');

        // والحلقة الاستثنائية تتجاوزه
        $res = $this->postJson('/api/discussion-circles', $this->payload(['time' => '10:15', 'capacity' => 4]))
            ->assertStatus(201);
        $this->assertSame(4, $res->json('circle.capacity'));
    }

    public function test_the_group_letter_is_optional_on_both_readings(): void
    {
        $this->actingAsRole('SCHEDULER');

        // مستقلّة عن مجموعتَي الكشف — تُترك فارغة
        $a = $this->postJson('/api/discussion-circles', $this->payload())->assertStatus(201);
        $this->assertNull($a->json('circle.groupLetter'));

        // أو مقابِلة لها واحدةً بواحدة — يُملأ الحرف
        $b = $this->postJson('/api/discussion-circles', $this->payload(['time' => '14:30', 'groupLetter' => 'A']))
            ->assertStatus(201);
        $this->assertSame('A', $b->json('circle.groupLetter'));
    }

    // ── السعة تُفرض فعلاً ──

    public function test_attaching_beyond_capacity_fills_then_skips_the_rest(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        $this->actingAsRole('SCHEDULER');

        $circle = $this->postJson('/api/discussion-circles',
            $this->payload(['evaluatorId' => $de->id, 'capacity' => 2]))->json('circle.id');

        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
            $ids[] = $c->id;
        }

        $res = $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => $ids])->assertOk();

        $this->assertSame(2, $res->json('attached'));
        $this->assertCount(2, $res->json('skipped'));
        $this->assertSame('تجاوز سعة الحلقة', $res->json('skipped.0.reason'));
        $this->assertSame(0, $res->json('circle.seatsLeft'));
        $this->assertSame(2, Schedule::where('circle_id', $circle)->count());
    }

    public function test_attaching_creates_plain_schedule_rows(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        $asst = $this->person('ASSISTANT');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);

        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles', $this->payload([
            'evaluatorId' => $de->id, 'assistantId' => $asst->id, 'location' => 'قاعة ٣',
        ]))->json('circle.id');

        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$c->id]])->assertOk();

        // صفٌّ عادي تلتقطه شاشات الحضور والكشف والتقييم بلا تعديل فيها
        $row = Schedule::where('circle_id', $circle)->first();
        $this->assertSame('discussion', $row->activity);
        $this->assertSame($de->id, $row->evaluator_id, 'مستشار الحلقة لا فراغ');
        $this->assertSame($asst->id, $row->assistant_id);
        $this->assertSame('12:30', substr((string) $row->schedule_time, 0, 5));
        $this->assertSame('قاعة ٣', $row->location);
    }

    public function test_a_circle_without_an_evaluator_refuses_attachment(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $this->actingAsRole('SCHEDULER');

        $circle = $this->postJson('/api/discussion-circles', $this->payload())->json('circle.id');
        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$c->id]])
            ->assertStatus(422);
    }

    public function test_the_same_candidate_is_not_attached_twice(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);

        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id]))->json('circle.id');

        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$c->id]])->assertOk();
        $res = $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$c->id]])->assertOk();

        $this->assertSame(0, $res->json('attached'));
        $this->assertSame('مُسنَد لهذه الحلقة أصلاً', $res->json('skipped.0.reason'));
        $this->assertSame(1, Schedule::where('circle_id', $circle)->count());
    }

    // ── منع ازدواج المستشار ──

    public function test_one_evaluator_cannot_hold_two_circles_at_the_same_instant(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id]))->assertStatus(201);
        $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id]))
            ->assertStatus(409);

        // ووقتٌ آخر يمرّ
        $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id, 'time' => '14:30']))
            ->assertStatus(201);
    }

    // ── الأهلية وحدّ القطاع ──

    public function test_an_interview_evaluator_cannot_run_a_circle(): void
    {
        $interviewer = $this->person('EVALUATOR');
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $interviewer->id]))
            ->assertStatus(422);
    }

    public function test_an_evaluator_from_another_sector_is_refused(): void
    {
        $other = $this->person('DISCUSSION_EVAL', 'MS');
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $other->id]))
            ->assertStatus(422);
    }

    public function test_a_candidate_from_another_sector_is_silently_out_of_scope(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        [$other] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'MS']);

        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id]))->json('circle.id');

        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$other->id]])
            ->assertStatus(422);
        $this->assertSame(0, Schedule::where('circle_id', $circle)->count());
    }

    // ── الحلقة تجرّ جلساتها ──

    public function test_moving_a_circle_moves_its_sessions(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);

        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id]))->json('circle.id');
        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$c->id]])->assertOk();

        $newDate = now()->addDays(3)->toDateString();
        $this->putJson("/api/discussion-circles/{$circle}", ['date' => $newDate, 'time' => '14:30'])->assertOk();

        $row = Schedule::where('circle_id', $circle)->first();
        $this->assertSame($newDate, $row->schedule_date->toDateString(), 'الجلسة تتبع حلقتها');
        $this->assertSame('14:30', substr((string) $row->schedule_time, 0, 5));
    }

    public function test_shrinking_capacity_below_the_attached_is_refused(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles',
            $this->payload(['evaluatorId' => $de->id, 'capacity' => 3]))->json('circle.id');

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
            $ids[] = $c->id;
        }
        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => $ids])->assertOk();

        $this->putJson("/api/discussion-circles/{$circle}", ['capacity' => 2])->assertStatus(422);
        $this->putJson("/api/discussion-circles/{$circle}", ['capacity' => 5])->assertOk();
    }

    // ── السحب والحذف ──

    public function test_detaching_removes_the_session_but_not_after_attendance(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);

        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id]))->json('circle.id');
        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$c->id]])->assertOk();

        $schedule = Schedule::where('circle_id', $circle)->first();
        Attendance::create(['schedule_id' => $schedule->id, 'status' => 'present', 'recorded_by' => null]);

        $this->deleteJson("/api/discussion-circles/{$circle}/detach", ['candidateId' => $c->id])
            ->assertStatus(422);

        Attendance::where('schedule_id', $schedule->id)->delete();
        $this->deleteJson("/api/discussion-circles/{$circle}/detach", ['candidateId' => $c->id])->assertOk();
        $this->assertSame(0, Schedule::where('circle_id', $circle)->count());
    }

    public function test_a_circle_with_participants_cannot_be_deleted(): void
    {
        $de = $this->person('DISCUSSION_EVAL');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);

        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles', $this->payload(['evaluatorId' => $de->id]))->json('circle.id');
        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$c->id]])->assertOk();

        $this->deleteJson("/api/discussion-circles/{$circle}")->assertStatus(422);

        $this->deleteJson("/api/discussion-circles/{$circle}/detach", ['candidateId' => $c->id])->assertOk();
        $this->deleteJson("/api/discussion-circles/{$circle}")->assertOk();
    }

    // ── الموجة ──

    public function test_a_circle_outside_its_period_range_is_refused(): void
    {
        $period = SchedulingPeriod::create([
            'name' => 'دورة ' . uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'draft',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/discussion-circles', $this->payload([
            'periodId' => $period->id, 'date' => now()->addDays(9)->toDateString(),
        ]))->assertStatus(422);

        $this->postJson('/api/discussion-circles', $this->payload([
            'periodId' => $period->id, 'date' => now()->addDay()->toDateString(),
        ]))->assertStatus(201);
    }

    public function test_attached_sessions_inherit_the_period(): void
    {
        $period = SchedulingPeriod::create([
            'name' => 'دورة ' . uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'draft',
        ]);
        $de = $this->person('DISCUSSION_EVAL');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);

        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles',
            $this->payload(['evaluatorId' => $de->id, 'periodId' => $period->id]))->json('circle.id');
        $this->postJson("/api/discussion-circles/{$circle}/attach", ['candidateIds' => [$c->id]])->assertOk();

        $this->assertSame($period->id, Schedule::where('circle_id', $circle)->first()->period_id);
    }

    // ── الصلاحيات ──

    public function test_viewing_needs_view_and_writing_needs_manage(): void
    {
        $this->actingAsRole('SCHEDULER');
        $circle = $this->postJson('/api/discussion-circles', $this->payload())->json('circle.id');

        $this->actingAsRole('OPERATIONS');   // schedule.view بلا manage
        $this->getJson('/api/discussion-circles')->assertOk()->assertJsonPath('canManage', false);
        $this->postJson('/api/discussion-circles', $this->payload(['time' => '14:30']))->assertStatus(403);
        $this->putJson("/api/discussion-circles/{$circle}", ['capacity' => 3])->assertStatus(403);
        $this->deleteJson("/api/discussion-circles/{$circle}")->assertStatus(403);
    }

    public function test_a_sector_bound_user_sees_only_their_own_circles(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/discussion-circles', $this->payload())->assertStatus(201);
        $this->postJson('/api/discussion-circles', $this->payload([
            'sectorId' => Sector::where('code', 'MS')->value('id'),
        ]))->assertStatus(201);

        $this->assertSame(2, DiscussionCircle::count());

        // لا دورَ محصورٌ بقطاع يملك schedule.view افتراضاً — تُمنح بالاستثناء
        // الفردي، وهي الحالة التي يحرسها الحصر أصلاً.
        $bound = $this->actingAsRole('DISCUSSION_EVAL', 'DW');
        \App\Models\UserPermissionOverride::create([
            'user_id' => $bound->id, 'permission' => 'schedule.view',
            'granted' => true, 'created_by' => $bound->id,
        ]);
        $this->actingAs($bound->fresh());

        $rows = $this->getJson('/api/discussion-circles')->assertOk()->json('circles');
        $this->assertCount(1, $rows, 'المحصور بقطاع لا يرى حلقات غيره');
    }
}
