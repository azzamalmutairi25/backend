<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SchedulingPeriod;
use App\Models\SchedulingWorkflowStep;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// سير عمل الجدولة — الخطوات الاثنتا عشرة بياناتٍ تُحرَّر، والموجة تُقاس عليها.
//
// الاختبارات تحرس ثلاثة: أن الإجراء المبذور يطابق المخطّط، وأن التحرير من
// الإعدادات يسري فعلاً، وأن الخطوة الآلية تُقاس ولا تُؤشَّر كذباً.
class SchedulingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(string $sectorCode = 'DW'): User
    {
        return User::create([
            'username' => 'ev_'.substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستشار اختبار',
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function makePeriod(array $over = []): SchedulingPeriod
    {
        return SchedulingPeriod::create(array_merge([
            'name' => 'دورة '.uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'draft',
        ], $over));
    }

    // ── التعريف ──

    public function test_the_twelve_steps_of_the_chart_are_seeded_in_order(): void
    {
        $steps = SchedulingWorkflowStep::ordered()->get();
        $this->assertCount(12, $steps, 'اثنتا عشرة خطوة كما في المخطّط');
        $this->assertSame('تحديد تواريخ الجدولة', $steps->first()->title_ar);
        $this->assertSame('توزيع كل قطاع على حدة على ملف PDF', $steps->last()->title_ar);
        $this->assertSame(range(1, 12), $steps->pluck('position')->all());
    }

    public function test_the_workflow_is_readable_by_a_scheduler_without_settings(): void
    {
        $this->actingAsRole('SCHEDULER');   // بلا settings.manage
        $res = $this->getJson('/api/settings/scheduling-workflow')->assertOk();
        $this->assertCount(12, $res->json('steps'));
        $this->assertFalse($res->json('canManage'));
    }

    public function test_editing_a_step_requires_settings_manage(): void
    {
        $step = SchedulingWorkflowStep::ordered()->first();
        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/settings/scheduling-workflow/{$step->id}", ['title' => 'محاولة'])->assertStatus(403);
        $this->postJson('/api/settings/scheduling-workflow', ['title' => 'محاولة'])->assertStatus(403);
        $this->deleteJson("/api/settings/scheduling-workflow/{$step->id}")->assertStatus(403);
    }

    public function test_admin_renames_reorders_and_deactivates_steps(): void
    {
        $this->actingAsRole('ADMIN');
        $steps = SchedulingWorkflowStep::ordered()->get();

        $this->putJson("/api/settings/scheduling-workflow/{$steps[0]->id}", [
            'title' => 'تحديد تواريخ الدورة',
        ])->assertOk();
        $this->assertSame('تحديد تواريخ الدورة', $steps[0]->fresh()->title_ar);

        // إطفاء خطوة يُخرجها من قائمة الموجة
        $this->putJson("/api/settings/scheduling-workflow/{$steps[11]->id}", ['isActive' => false])->assertOk();
        $this->assertFalse($steps[11]->fresh()->is_active);

        // عكس الترتيب
        $reversed = $steps->pluck('id')->reverse()->values()->all();
        $this->putJson('/api/settings/scheduling-workflow/reorder', ['ids' => $reversed])->assertOk();
        $this->assertSame($reversed, SchedulingWorkflowStep::ordered()->pluck('id')->all());
    }

    public function test_a_partial_reorder_is_refused(): void
    {
        $this->actingAsRole('ADMIN');
        $someIds = SchedulingWorkflowStep::ordered()->take(3)->pluck('id')->all();
        $this->putJson('/api/settings/scheduling-workflow/reorder', ['ids' => $someIds])->assertStatus(422);
    }

    public function test_an_unknown_auto_key_is_refused(): void
    {
        $this->actingAsRole('ADMIN');
        $this->postJson('/api/settings/scheduling-workflow', [
            'title' => 'خطوة بمفتاح وهمي',
            'autoKey' => 'period.does_not_exist',
        ])->assertStatus(422);
    }

    public function test_a_new_step_is_appended_and_shows_on_periods(): void
    {
        $period = $this->makePeriod();
        $this->actingAsRole('ADMIN');

        $this->postJson('/api/settings/scheduling-workflow', [
            'title' => 'تسليم نسخة لإدارة الأمن',
            'description' => 'إجراء داخلي أُضيف بتعميم',
        ])->assertStatus(201);

        $this->assertSame(13, SchedulingWorkflowStep::count());
        $res = $this->getJson("/api/scheduling-periods/{$period->id}/workflow")->assertOk();
        $titles = collect($res->json('steps'))->pluck('title')->all();
        $this->assertContains('تسليم نسخة لإدارة الأمن', $titles);
    }

    public function test_the_last_step_cannot_be_deleted(): void
    {
        $this->actingAsRole('ADMIN');
        $keep = SchedulingWorkflowStep::ordered()->first();
        SchedulingWorkflowStep::where('id', '!=', $keep->id)->delete();

        $this->deleteJson("/api/settings/scheduling-workflow/{$keep->id}")->assertStatus(422);
        $this->assertSame(1, SchedulingWorkflowStep::count());
    }

    // ── القياس على الموجة ──

    public function test_automatic_steps_are_measured_from_the_period_state(): void
    {
        $period = $this->makePeriod();
        $this->actingAsRole('SCHEDULER');

        $byKey = fn ($res, $key) => collect($res->json('steps'))->firstWhere('autoKey', $key);

        $res = $this->getJson("/api/scheduling-periods/{$period->id}/workflow")->assertOk();
        // المدى محدَّد عند الإنشاء ⇒ الخطوة الأولى مكتملة بلا تأشير
        $this->assertSame('done', $byKey($res, 'period.dates')['status']);
        // ولا أسماء ولا جلسات بعد
        $this->assertSame('pending', $byKey($res, 'period.assessors')['status']);
        $this->assertSame('pending', $byKey($res, 'period.participants')['status']);

        // أدرِج اسماً ⇒ تكتمل خطوة الأسماء وحدها
        $ev = $this->evaluator();
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $ev->id, 'activity' => 'interview', 'seat' => 'evaluator']],
        ])->assertOk();

        $res = $this->getJson("/api/scheduling-periods/{$period->id}/workflow")->assertOk();
        $this->assertSame('done', $byKey($res, 'period.assessors')['status']);
        $this->assertSame('pending', $byKey($res, 'period.participants')['status']);
    }

    public function test_an_automatic_step_unticks_itself_when_its_condition_is_undone(): void
    {
        $period = $this->makePeriod();
        $ev = $this->evaluator();
        $this->actingAsRole('SCHEDULER');

        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", [
            'rows' => [['userId' => $ev->id, 'activity' => 'interview', 'seat' => 'evaluator']],
        ])->assertOk();
        $byKey = fn ($res, $key) => collect($res->json('steps'))->firstWhere('autoKey', $key);
        $this->assertSame('done', $byKey($this->getJson("/api/scheduling-periods/{$period->id}/workflow"), 'period.assessors')['status']);

        // اسحب الاسم ⇒ الخطوة تعود معلّقة من نفسها
        $this->putJson("/api/scheduling-periods/{$period->id}/assessors", ['rows' => []])->assertOk();
        $this->assertSame('pending', $byKey($this->getJson("/api/scheduling-periods/{$period->id}/workflow"), 'period.assessors')['status']);
    }

    public function test_an_automatic_step_cannot_be_ticked_by_hand(): void
    {
        $period = $this->makePeriod();
        $auto = SchedulingWorkflowStep::whereNotNull('auto_key')->first();

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/workflow/{$auto->id}", ['status' => 'done'])
            ->assertStatus(422);
        $this->assertDatabaseCount('period_step_progress', 0);
    }

    public function test_a_manual_step_is_ticked_and_untickable(): void
    {
        $period = $this->makePeriod();
        $manual = SchedulingWorkflowStep::whereNull('auto_key')->first();

        $actor = $this->actingAsRole('SCHEDULER');
        $res = $this->postJson("/api/scheduling-periods/{$period->id}/workflow/{$manual->id}", [
            'status' => 'done',
        ])->assertOk();

        $row = collect($res->json('steps'))->firstWhere('id', $manual->id);
        $this->assertSame('done', $row['status']);
        $this->assertSame($actor->full_name, $row['doneByName']);

        $this->postJson("/api/scheduling-periods/{$period->id}/workflow/{$manual->id}", ['status' => 'pending'])->assertOk();
        $this->assertDatabaseCount('period_step_progress', 0);
    }

    public function test_skipping_a_step_requires_a_reason_and_leaves_the_denominator(): void
    {
        $period = $this->makePeriod();
        $manual = SchedulingWorkflowStep::whereNull('auto_key')->first();
        $this->actingAsRole('SCHEDULER');

        $before = $this->getJson("/api/scheduling-periods/{$period->id}/workflow")->json('summary.required');

        $this->postJson("/api/scheduling-periods/{$period->id}/workflow/{$manual->id}", ['status' => 'skipped'])
            ->assertStatus(422);

        $res = $this->postJson("/api/scheduling-periods/{$period->id}/workflow/{$manual->id}", [
            'status' => 'skipped', 'note' => 'لا عسكريين في هذه الدورة',
        ])->assertOk();

        $this->assertSame($before - 1, $res->json('summary.required'), 'المستثناة تخرج من المقام لا تبقى ناقصة');
        $row = collect($res->json('steps'))->firstWhere('id', $manual->id);
        $this->assertSame('skipped', $row['status']);
        $this->assertSame('لا عسكريين في هذه الدورة', $row['note']);
    }

    public function test_an_optional_step_is_outside_the_percentage(): void
    {
        $period = $this->makePeriod();
        $this->actingAsRole('ADMIN');
        $before = $this->getJson("/api/scheduling-periods/{$period->id}/workflow")->json('summary.required');

        $step = SchedulingWorkflowStep::whereNull('auto_key')->first();
        $this->putJson("/api/settings/scheduling-workflow/{$step->id}", ['isRequired' => false])->assertOk();

        $this->assertSame($before - 1, $this->getJson("/api/scheduling-periods/{$period->id}/workflow")->json('summary.required'));
    }

    public function test_a_deactivated_step_disappears_from_the_period_checklist(): void
    {
        $period = $this->makePeriod();
        $step = SchedulingWorkflowStep::ordered()->first();

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/settings/scheduling-workflow/{$step->id}", ['isActive' => false])->assertOk();

        $ids = collect($this->getJson("/api/scheduling-periods/{$period->id}/workflow")->json('steps'))->pluck('id')->all();
        $this->assertNotContains($step->id, $ids);
    }

    public function test_deleting_a_step_drops_its_marks(): void
    {
        $period = $this->makePeriod();
        $manual = SchedulingWorkflowStep::whereNull('auto_key')->first();

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/workflow/{$manual->id}", ['status' => 'done'])->assertOk();
        $this->assertDatabaseCount('period_step_progress', 1);

        $this->actingAsRole('ADMIN');
        $this->deleteJson("/api/settings/scheduling-workflow/{$manual->id}")->assertOk();
        $this->assertDatabaseCount('period_step_progress', 0);
    }

    public function test_approval_completes_its_step_and_lifts_the_percentage(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = $this->makePeriod();
        $ev = $this->evaluator();

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '10:15',
            'evaluatorId' => $ev->id, 'periodId' => $period->id,
        ])->assertStatus(201);

        $byKey = fn ($res, $key) => collect($res->json('steps'))->firstWhere('autoKey', $key);
        $before = $this->getJson("/api/scheduling-periods/{$period->id}/workflow");
        $this->assertSame('done', $byKey($before, 'period.participants')['status']);
        $this->assertSame('done', $byKey($before, 'period.evaluators_linked')['status']);
        $this->assertSame('pending', $byKey($before, 'period.approved')['status']);

        $this->postJson("/api/scheduling-periods/{$period->id}/submit")->assertOk();
        $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertOk();

        $after = $this->getJson("/api/scheduling-periods/{$period->id}/workflow");
        $this->assertSame('done', $byKey($after, 'period.approved')['status']);
        $this->assertGreaterThan($before->json('summary.percent'), $after->json('summary.percent'));
    }

    public function test_marking_requires_schedule_manage(): void
    {
        $period = $this->makePeriod();
        $manual = SchedulingWorkflowStep::whereNull('auto_key')->first();

        $this->actingAsRole('OPERATIONS');   // schedule.view بلا manage
        $this->getJson("/api/scheduling-periods/{$period->id}/workflow")->assertOk();
        $this->postJson("/api/scheduling-periods/{$period->id}/workflow/{$manual->id}", ['status' => 'done'])
            ->assertStatus(403);
    }

    public function test_deleting_a_period_takes_its_marks_with_it(): void
    {
        $period = $this->makePeriod();
        $manual = SchedulingWorkflowStep::whereNull('auto_key')->first();

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/workflow/{$manual->id}", ['status' => 'done'])->assertOk();
        $this->deleteJson("/api/scheduling-periods/{$period->id}")->assertOk();

        $this->assertDatabaseCount('period_step_progress', 0);
        $this->assertSame(12, SchedulingWorkflowStep::count(), 'التعريف إعدادٌ يبقى بعد حذف الموجة');
    }
}
