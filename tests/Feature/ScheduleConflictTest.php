<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Schedule;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// تعارض الوقت — الشخص الواحد في اللحظة الواحدة.
//
// «النصاب عدّاد لا سدّ» ونصفُه الثاني «تعارض الوقت يُمنع». والحدّ بينهما هو ما
// تحرسه هذه الاختبارات: ما يُمنع خطأُ إدخال (شخصٌ في مكانين)، وما لا يُمنع
// قرارٌ إداري (تجاوز النصاب) ولا الجلسةُ الجماعية (مشرفٌ وعدّة مشاركين).
class ScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function person(string $roleCode, string $sectorCode = 'DW'): User
    {
        return User::create([
            'username' => 'u_'.substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستخدم '.$roleCode,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function makeSession(int $candidateId, string $activity, string $date, ?string $time, array $over = [])
    {
        return $this->postJson('/api/schedules', array_merge([
            'candidateId' => $candidateId,
            'activity' => $activity,
            'date' => $date,
            'time' => $time,
        ], $over));
    }

    // ── ما يُمنع ──

    public function test_an_evaluator_cannot_hold_two_interviews_at_the_same_instant(): void
    {
        [$a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$b] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->person('EVALUATOR');
        $date = now()->addDay()->toDateString();

        $this->actingAsRole('SCHEDULER');
        $this->makeSession($a->id, 'interview', $date, '10:15', ['evaluatorId' => $ev->id])->assertStatus(201);

        $res = $this->makeSession($b->id, 'interview', $date, '10:15', ['evaluatorId' => $ev->id])
            ->assertStatus(409);
        $this->assertStringContainsString('المقيّم', $res->json('error'));
    }

    public function test_an_assistant_cannot_be_in_two_interviews_at_the_same_instant(): void
    {
        [$a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$b] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $as = $this->person('ASSISTANT');
        $date = now()->addDay()->toDateString();

        $this->actingAsRole('SCHEDULER');
        $this->makeSession($a->id, 'interview', $date, '10:15', ['assistantId' => $as->id])->assertStatus(201);
        $this->makeSession($b->id, 'interview', $date, '10:15', ['assistantId' => $as->id])->assertStatus(409);
    }

    public function test_a_participant_cannot_be_in_two_sessions_at_the_same_instant(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $date = now()->addDay()->toDateString();

        $this->actingAsRole('SCHEDULER');
        $this->makeSession($c->id, 'interview', $date, '10:15')->assertStatus(201);

        // نشاطٌ آخر لا يعفيه: الشخص لا يكون في قاعتين
        $res = $this->makeSession($c->id, 'measurement', $date, '10:15')->assertStatus(409);
        $this->assertStringContainsString('مشارك', $res->json('error'));
    }

    public function test_moving_a_session_onto_a_taken_slot_is_refused(): void
    {
        [$a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$b] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->person('EVALUATOR');
        $date = now()->addDay()->toDateString();

        $this->actingAsRole('SCHEDULER');
        $this->makeSession($a->id, 'interview', $date, '10:15', ['evaluatorId' => $ev->id])->assertStatus(201);
        $id = $this->makeSession($b->id, 'interview', $date, '12:30', ['evaluatorId' => $ev->id])
            ->assertStatus(201)->json('scheduleId');

        // النقل كالإنشاء — نفس القيد
        $this->putJson('/api/schedules/'.$id, ['time' => '10:15'])->assertStatus(409);
    }

    // ── ما لا يُمنع ──

    public function test_a_group_activity_takes_many_participants_at_one_instant(): void
    {
        [$a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$b] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->person('EVALUATOR');
        $date = now()->addDay()->toDateString();

        // أدوات القياس والتمرين التكاملي جلستان جماعيّتان: مشرفٌ واحد وعدّة
        // مشاركين في القاعة نفسها، ولكلٍّ صفُّه. قيدٌ يشملهما يمنع إدخالاً سليماً.
        $this->actingAsRole('SCHEDULER');
        $this->makeSession($a->id, 'measurement', $date, '10:15', ['evaluatorId' => $ev->id])->assertStatus(201);
        $this->makeSession($b->id, 'measurement', $date, '10:15', ['evaluatorId' => $ev->id])->assertStatus(201);

        $this->makeSession($a->id, 'integration', $date, '12:30', ['evaluatorId' => $ev->id])->assertStatus(201);
        $this->makeSession($b->id, 'integration', $date, '12:30', ['evaluatorId' => $ev->id])->assertStatus(201);

        $this->assertSame(4, Schedule::whereIn('activity', ['measurement', 'integration'])->count());
    }

    public function test_two_evaluators_share_an_instant(): void
    {
        [$a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$b] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $one = $this->person('EVALUATOR');
        $two = $this->person('EVALUATOR');
        $date = now()->addDay()->toDateString();

        $this->actingAsRole('SCHEDULER');
        $this->makeSession($a->id, 'interview', $date, '10:15', ['evaluatorId' => $one->id])->assertStatus(201);
        $this->makeSession($b->id, 'interview', $date, '10:15', ['evaluatorId' => $two->id])->assertStatus(201);
    }

    public function test_sessions_without_an_evaluator_do_not_collide(): void
    {
        [$a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$b] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $date = now()->addDay()->toDateString();

        // NULL لا يزاحم NULL — القيد جزئيّ لهذا السبب
        $this->actingAsRole('SCHEDULER');
        $this->makeSession($a->id, 'interview', $date, '10:15')->assertStatus(201);
        $this->makeSession($b->id, 'interview', $date, '10:15')->assertStatus(201);
    }

    public function test_over_quota_is_still_only_a_warning(): void
    {
        [$a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        [$b] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $ev = $this->person('EVALUATOR');
        $date = now()->addDay()->toDateString();

        // نفس المقيّم مرّتين في اليوم بأوقاتٍ مختلفة — تجاوزٌ للنصاب لا تعارضٌ
        // في الوقت، فيمرّ: القرار للمستخدم لا للخوارزمية.
        $this->actingAsRole('SCHEDULER');
        $this->makeSession($a->id, 'interview', $date, '10:15', ['evaluatorId' => $ev->id])->assertStatus(201);
        $this->makeSession($b->id, 'interview', $date, '12:30', ['evaluatorId' => $ev->id])->assertStatus(201);
    }
}
