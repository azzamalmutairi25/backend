<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// إسناد غير المقابلة: حلقة النقاش والقياس، ومقعد المساعد.
//
// قبل هذا كانت شاشة الجدولة تُصفّر المقيّم لكل نشاطٍ غير المقابلة، فتُحفظ حلقة
// النقاش بلا مستشار ولا يجدها في شاشته. والمساعد عمودٌ في القاعدة يُطبع في كشف
// الحضور بلا مسارٍ يُرجع قائمته ولا حقلٍ في الشاشة.
class DiscussionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function person(string $roleCode, string $sectorCode = 'DW'): User
    {
        // القطاع للمحصورين بقطاعٍ وحدهم — كما يفرض UserController::sectorRuleError.
        // كان المساعد يكتبه لكل دور، فيُعطي MEASURE_SUPER قطاعاً لا يملكه في
        // الإنتاج أبداً — فمرّ اختبارُ «مشرفو القياس يُبلَغون» وهو في الشاشة
        // قائمةٌ فارغة. مساعدُ اختبارٍ يتجاوز قاعدة المسار يُخفي العطل لا يكشفه.
        $bound = in_array($roleCode, User::SECTOR_BOUND_ROLES, true);

        return User::create([
            'username' => 'u_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستخدم ' . $roleCode,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'sector_id' => $bound ? Sector::where('code', $sectorCode)->value('id') : null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    public function test_discussion_lists_discussion_evaluators_not_interviewers(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $interviewer = $this->person('EVALUATOR');
        $discussion = $this->person('DISCUSSION_EVAL');

        $this->actingAsRole('SCHEDULER');

        $ids = fn ($activity) => collect(
            $this->getJson("/api/candidates/{$c->id}/assessors?activity={$activity}&seat=evaluator")
                ->assertOk()->json('assessors')
        )->pluck('id')->all();

        $this->assertSame([$interviewer->id], $ids('interview'));
        $this->assertSame([$discussion->id], $ids('discussion'));
    }

    public function test_a_discussion_session_keeps_its_evaluator(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $de = $this->person('DISCUSSION_EVAL');

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id,
            'activity' => 'discussion',
            'date' => now()->addDay()->toDateString(),
            'time' => '12:30',
            'evaluatorId' => $de->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('schedules', [
            'candidate_id' => $c->id, 'activity' => 'discussion', 'evaluator_id' => $de->id,
        ]);
    }

    public function test_an_assistant_can_be_attached_and_read_back(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->person('EVALUATOR');
        $assistant = $this->person('ASSISTANT');

        $this->actingAsRole('SCHEDULER');

        // المقعد الثاني له قائمته الخاصة
        $list = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=assistant")
            ->assertOk()->json('assessors'))->pluck('id')->all();
        $this->assertSame([$assistant->id], $list);

        $this->postJson('/api/schedules', [
            'candidateId' => $c->id,
            'activity' => 'interview',
            'date' => now()->addDay()->toDateString(),
            'time' => '10:15',
            'evaluatorId' => $ev->id,
            'assistantId' => $assistant->id,
        ])->assertStatus(201);

        // ويعود في قائمة الجلسات — كان يُكتب ولا يُقرأ
        $row = collect($this->getJson('/api/schedules')->assertOk()->json('schedules'))
            ->firstWhere('candidateId', $c->id);
        $this->assertSame($assistant->id, $row['assistantId']);
        $this->assertSame($assistant->full_name, $row['assistantName']);
    }

    public function test_cross_sector_assignment_returns_a_confirmable_conflict(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $otherSector = $this->person('EVALUATOR', 'MS');

        $this->actingAsRole('SCHEDULER');   // يملك candidate.cross_sector
        $payload = [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => now()->addDay()->toDateString(), 'time' => '10:15',
            'evaluatorId' => $otherSector->id,
        ];

        $this->postJson('/api/schedules', $payload)
            ->assertStatus(409)
            ->assertJsonPath('requiresConfirmation', true)
            ->assertJsonPath('confirmField', 'confirmCrossSector');

        // وبالتأكيد يمرّ ويُدوَّن التجاوز
        $this->postJson('/api/schedules', $payload + ['confirmCrossSector' => true])->assertStatus(201);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CREATE_SCHEDULE_CROSS_SECTOR']);
    }

    public function test_an_assistant_from_another_sector_is_caught_too(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $assistant = $this->person('ASSISTANT', 'MS');

        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => now()->addDay()->toDateString(), 'time' => '10:15',
            'assistantId' => $assistant->id,
        ])->assertStatus(409)->assertJsonPath('requiresConfirmation', true);
    }

    public function test_measurement_supervisors_are_reachable_for_measurement(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $sup = $this->person('MEASURE_SUPER');

        $this->actingAsRole('SCHEDULER');
        $ids = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=measurement&seat=evaluator")
            ->assertOk()->json('assessors'))->pluck('id')->all();
        $this->assertSame([$sup->id], $ids);
    }

    public function test_integration_falls_back_to_interview_evaluators(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->person('EVALUATOR');

        $this->actingAsRole('SCHEDULER');
        // التمرين التكاملي لا صفّ له في خريطة الاستقبال — يقع على مقيّم المقابلة
        // بدل قائمةٍ فارغة تُفرِغ الشاشة بلا سبب ظاهر
        $ids = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=integration&seat=evaluator")
            ->assertOk()->json('assessors'))->pluck('id')->all();
        $this->assertSame([$ev->id], $ids);
    }

    public function test_updating_a_session_can_set_the_assistant(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $assistant = $this->person('ASSISTANT');

        $this->actingAsRole('SCHEDULER');
        $res = $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => now()->addDay()->toDateString(), 'time' => '10:15',
        ])->assertStatus(201);

        $id = $res->json('scheduleId');
        $this->putJson("/api/schedules/{$id}", ['assistantId' => $assistant->id])->assertOk();
        $this->assertSame($assistant->id, Schedule::find($id)->assistant_id);
    }
}
