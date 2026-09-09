<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  كشف اليوم — من جلسات ذلك اليوم، لا من قاعدة المشاركين كلّها.
//
//  كانت القائمة تسرد كلَّ دورةٍ لم تنتهِ: من موعده بعد شهرين ومن لم يُجدوَل
//  قطّ يظهران بالتساوي مع من موعده اليوم. فكان الموظّف يستقبل من قائمةٍ لا
//  تعني اليوم في شيء.
// ════════════════════════════════════════════════════════════
class ReceptionDayRosterTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function scheduleOn(string $date, array $attrs = []): array
    {
        [$c, $a] = $this->makeCandidate(array_merge(['status' => 'scheduled'], $attrs));
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => $date, 'schedule_time' => '09:30',
            'activity' => 'interview',
        ]);

        return [$c, $a];
    }

    private function roster(?string $date = null): array
    {
        $res = $this->getJson('/api/reception'.($date ? "?date={$date}" : ''))->assertOk();

        return $res->json('expected');
    }

    // ═══ الكشف ═══

    public function test_the_roster_holds_todays_scheduled_and_nobody_else(): void
    {
        [, $today] = $this->scheduleOn(now()->toDateString());
        [, $later] = $this->scheduleOn(now()->addMonths(2)->toDateString());
        [, $never] = $this->makeCandidate(['status' => 'scheduled']);   // بلا جلسة أصلاً

        $this->actingAsRole('RECEPTIONIST');
        $ids = collect($this->roster()['rows'])->pluck('assessmentId');

        $this->assertTrue($ids->contains($today->id), 'من موعده اليوم');
        $this->assertFalse($ids->contains($later->id), 'ومن موعده بعد شهرين ليس منتظَر اليوم');
        $this->assertFalse($ids->contains($never->id), 'ومن لم يُجدوَل قطّ ليس منتظَراً');
    }

    public function test_the_roster_shows_the_appointment_times(): void
    {
        [, $a] = $this->scheduleOn(now()->toDateString());

        $this->actingAsRole('RECEPTIONIST');
        $row = collect($this->roster()['rows'])->firstWhere('assessmentId', $a->id);

        $this->assertSame('09:30', $row['sessions'][0]['time']);
        $this->assertSame('المقابلة الشخصية', $row['sessions'][0]['activity']);
        $this->assertFalse($row['offRoster']);
    }

    public function test_a_day_with_no_sessions_has_an_empty_roster(): void
    {
        $this->scheduleOn(now()->addWeek()->toDateString());

        $this->actingAsRole('RECEPTIONIST');
        $this->assertSame(0, $this->roster()['total'], 'لا جلسة اليوم ⇒ لا منتظَر');
    }

    // ── والبحث يبقى منفذاً للاستثناء ──
    // حصرُ الشاشة في المجدولين يُعمي الموظّف عمّن حضر بلا جلسة مسجَّلة
    public function test_searching_a_code_finds_the_unscheduled_and_marks_them(): void
    {
        [, $never] = $this->makeCandidate(['status' => 'scheduled', 'code' => 'ZZ9001']);

        $this->actingAsRole('RECEPTIONIST');
        $rows = collect($this->getJson('/api/reception?q=ZZ9001')->assertOk()->json('expected.rows'));
        $row = $rows->firstWhere('assessmentId', $never->id);

        $this->assertNotNull($row, 'يُوجَد بالبحث فيُستقبَل');
        $this->assertTrue($row['offRoster'], 'ويُعرَف أنه خارج كشف اليوم');
    }

    public function test_an_arrived_participant_leaves_the_expected_list(): void
    {
        [, $a] = $this->scheduleOn(now()->toDateString());

        $this->actingAsRole('RECEPTIONIST');
        $this->assertCount(1, $this->roster()['rows']);

        $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])->assertStatus(201);
        $this->assertCount(0, $this->roster()['rows'], 'من وصل خرج من المنتظَرين');
    }

    // ═══ اليوم فقط ═══

    public function test_arrival_is_recorded_on_its_own_day(): void
    {
        [, $a] = $this->scheduleOn(now()->toDateString());

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/reception/arrive', [
            'assessmentId' => $a->id, 'date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonPath('error', 'الوصول يُسجَّل في يومه — كشفُ اليوم لا يقبل تاريخاً آخر');

        $this->postJson('/api/reception/arrive', [
            'assessmentId' => $a->id, 'date' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    // والقراءة تبقى مفتوحة — المراجعة لا تُفسد شيئاً، والكتابة تُفسد
    public function test_a_past_day_is_readable_and_flagged(): void
    {
        $this->actingAsRole('RECEPTIONIST');
        $past = now()->subWeek()->toDateString();

        $res = $this->getJson("/api/reception?date={$past}")->assertOk();
        $this->assertSame($past, $res->json('date'));
        $this->assertFalse($res->json('isToday'));
        $this->assertTrue($this->getJson('/api/reception')->json('isToday'));
    }

    // ═══ الإشعار عند الاعتماد ═══

    public function test_approving_a_wave_tells_reception(): void
    {
        $clerk = $this->actingAsRole('RECEPTIONIST');
        $period = SchedulingPeriod::create([
            'name' => 'موجة الإشعار',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'status' => 'pending_center',
        ]);
        [$c, $a] = $this->scheduleOn(now()->toDateString());
        Schedule::where('assessment_id', $a->id)->update(['period_id' => $period->id]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertOk();

        $this->assertTrue(
            Notification::where('recipient_id', $clerk->id)
                ->where('title', 'اعتُمدت الجدولة — كشوف الأيام جاهزة')->exists(),
            'بلا إشعارٍ لا يعلم الاستقبال إلا بفتح شاشته يدوياً'
        );
    }

    // بالصلاحية لا بالدور: من مُنِح العرض باستثناءٍ فردي يستقبل فعلاً
    public function test_the_notice_follows_the_permission_not_the_role(): void
    {
        $granted = $this->actingAsRole('DATA_ENTRY');
        $granted->permissionOverrides()->create([
            'permission' => Permissions::RECEPTION_VIEW, 'granted' => true,
        ]);

        $period = SchedulingPeriod::create([
            'name' => 'موجة الصلاحية',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'pending_center',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertOk();

        $this->assertTrue(
            Notification::where('recipient_id', $granted->id)
                ->where('entity_type', 'scheduling_period')->exists()
        );
    }
}
