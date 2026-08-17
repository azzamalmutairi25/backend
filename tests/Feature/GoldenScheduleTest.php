<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\GoldenScheduleEntry;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// الجدول الذهبي (الخطوة ٦) وترحيل التواريخ (الخطوة ١٠).
//
// الجدول **سجلٌّ يُكتب** لا عرضٌ محسوب: الرمز والتاريخ يُنسخان، والصفّ اليدوي
// يبقى بعد إعادة المزامنة.
class GoldenScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function period(): SchedulingPeriod
    {
        return SchedulingPeriod::create([
            'name' => 'دورة ' . uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'draft',
        ]);
    }

    private function evaluator(string $sectorCode = 'DW'): User
    {
        return User::create([
            'username' => 'ev_' . substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مقيّم',
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    /** يُنشئ جلسةً في الموجة ويرجع [المرشّح، الدورة] */
    private function makeSession(SchedulingPeriod $p, string $sectorCode = 'DW', ?string $date = null): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => $sectorCode]);
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id,
            'activity' => 'interview',
            'date' => $date ?: $p->start_date->toDateString(),
            'time' => '10:15',
            'periodId' => $p->id,
        ])->assertStatus(201);
        return [$c, $a];
    }

    // ── ترحيل التواريخ (الخطوة ١٠) ──

    public function test_scheduling_writes_the_assessment_dates(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        [, $a] = $this->makeSession($p);

        $this->assertSame($p->start_date->toDateString(), $a->fresh()->first_session_date->toDateString());
        $this->assertSame($p->start_date->toDateString(), $a->fresh()->last_session_date->toDateString());
    }

    public function test_a_later_session_moves_the_last_date_only(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        [$c, $a] = $this->makeSession($p);

        $late = $p->end_date->toDateString();
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'discussion',
            'date' => $late, 'time' => '12:30', 'periodId' => $p->id,
        ])->assertStatus(201);

        $fresh = $a->fresh();
        $this->assertSame($p->start_date->toDateString(), $fresh->first_session_date->toDateString());
        $this->assertSame($late, $fresh->last_session_date->toDateString());
    }

    public function test_deleting_the_only_session_clears_both_dates(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        [$c, $a] = $this->makeSession($p);

        $id = Schedule::where('candidate_id', $c->id)->value('id');
        $this->deleteJson("/api/schedules/{$id}")->assertOk();

        $fresh = $a->fresh();
        $this->assertNull($fresh->first_session_date);
        $this->assertNull($fresh->last_session_date);
    }

    public function test_updating_a_session_date_follows_through(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        [$c, $a] = $this->makeSession($p);

        $id = Schedule::where('candidate_id', $c->id)->value('id');
        $newDate = $p->end_date->toDateString();
        $this->putJson("/api/schedules/{$id}", ['date' => $newDate])->assertOk();

        $this->assertSame($newDate, $a->fresh()->first_session_date->toDateString());
    }

    // ── المزامنة (الخطوة ٦) ──

    public function test_sync_is_idempotent(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        $this->makeSession($p);
        $this->makeSession($p);

        $first = $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertOk();
        $this->assertSame(2, $first->json('created'));
        $this->assertSame(2, GoldenScheduleEntry::count());

        $second = $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertOk();
        $this->assertSame(0, $second->json('created'), 'المزامنة الثانية لا تضاعف');
        $this->assertSame(2, GoldenScheduleEntry::count());
    }

    public function test_a_manual_row_survives_a_resync(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/golden-schedule', [
            'periodId' => $p->id,
            'date' => $p->start_date->toDateString(),
            'participantCode' => 'YDW-001',
            'sectorId' => Sector::where('code', 'DW')->value('id'),
            'note' => 'أُضيف بتعميم',
        ])->assertStatus(201);

        $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertOk()
            ->assertJsonPath('keptManual', 1);

        $row = GoldenScheduleEntry::where('participant_code', 'YDW-001')->first();
        $this->assertSame('manual', $row->source);
        $this->assertSame('أُضيف بتعميم', $row->note);
    }

    public function test_a_manual_row_outside_the_range_is_refused(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/golden-schedule', [
            'periodId' => $p->id,
            'date' => now()->addDays(20)->toDateString(),
            'participantCode' => 'X-1',
            'sectorId' => Sector::where('code', 'DW')->value('id'),
        ])->assertStatus(422);
    }

    public function test_the_same_code_twice_in_one_day_is_refused(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        $payload = [
            'periodId' => $p->id,
            'date' => $p->start_date->toDateString(),
            'participantCode' => 'X-1',
            'sectorId' => Sector::where('code', 'DW')->value('id'),
        ];
        $this->postJson('/api/golden-schedule', $payload)->assertStatus(201);
        $this->postJson('/api/golden-schedule', $payload)->assertStatus(409);
    }

    // ── العرض والحصر ──

    public function test_the_grid_groups_by_sector_and_spans_the_period_days(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        $this->makeSession($p, 'DW');
        $this->makeSession($p, 'MS');
        $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertOk();

        $res = $this->getJson("/api/golden-schedule?periodId={$p->id}")->assertOk();
        $this->assertCount(3, $res->json('days'), 'أيام الموجة كلها أعمدة');
        $this->assertSame(2, $res->json('total'));
        $this->assertCount(2, $res->json('sectors'), 'قطاعان');
    }

    public function test_a_classified_code_is_hidden_from_a_reader_without_clearance(): void
    {
        $p = $this->period();
        $this->actingAsRole('ADMIN');   // يرى المصنّفين فيُنشئ الجلسة

        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW', 'classification' => 'secret']);
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $p->start_date->toDateString(), 'time' => '10:15', 'periodId' => $p->id,
        ])->assertStatus(201);
        $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertOk();
        $this->assertSame(1, GoldenScheduleEntry::count());

        // مسؤول الجدولة لا يملك candidate.view_classified
        $this->actingAsRole('SCHEDULER');
        $this->assertSame(0, $this->getJson("/api/golden-schedule?periodId={$p->id}")->assertOk()->json('total'));
    }

    public function test_the_printed_document_is_html_and_audited(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        $this->makeSession($p);
        $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertOk();

        $res = $this->get("/api/golden-schedule/document?periodId={$p->id}")->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('الجدول الذهبي', $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EXPORT_GOLDEN_SCHEDULE']);
    }

    public function test_writing_needs_manage_and_reading_needs_view(): void
    {
        $p = $this->period();
        $this->actingAsRole('OPERATIONS');   // schedule.view بلا manage

        $this->getJson("/api/golden-schedule?periodId={$p->id}")->assertOk();
        $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertStatus(403);
        $this->postJson('/api/golden-schedule', [
            'periodId' => $p->id, 'date' => $p->start_date->toDateString(),
            'participantCode' => 'X', 'sectorId' => Sector::where('code', 'DW')->value('id'),
        ])->assertStatus(403);
    }

    // ── سير العمل: الخطوتان صارتا آليّتين ──

    public function test_the_two_steps_are_now_measured_automatically(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        $this->makeSession($p);

        $byKey = fn ($res, $k) => collect($res->json('steps'))->firstWhere('autoKey', $k);

        $before = $this->getJson("/api/scheduling-periods/{$p->id}/workflow")->assertOk();
        // التواريخ تُكتب مع الجدولة، فالخطوة العاشرة تكتمل من نفسها
        $this->assertSame('done', $byKey($before, 'period.dates_written')['status']);
        // والجدول الذهبي لم يُزامَن بعد
        $this->assertSame('pending', $byKey($before, 'period.golden_synced')['status']);

        $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertOk();

        $after = $this->getJson("/api/scheduling-periods/{$p->id}/workflow")->assertOk();
        $this->assertSame('done', $byKey($after, 'period.golden_synced')['status']);
    }

    public function test_a_new_session_after_sync_reopens_the_step(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        $this->makeSession($p);
        $this->postJson("/api/golden-schedule/{$p->id}/sync")->assertOk();

        $byKey = fn ($res, $k) => collect($res->json('steps'))->firstWhere('autoKey', $k);
        $this->assertSame('done', $byKey($this->getJson("/api/scheduling-periods/{$p->id}/workflow"), 'period.golden_synced')['status']);

        // جلسةٌ جديدة لم تُرحَّل ⇒ الخطوة تعود معلّقة من نفسها
        $this->makeSession($p);
        $this->assertSame('pending', $byKey($this->getJson("/api/scheduling-periods/{$p->id}/workflow"), 'period.golden_synced')['status']);
    }

    public function test_deleting_a_period_takes_its_golden_rows(): void
    {
        $p = $this->period();
        $this->actingAsRole('SCHEDULER');
        $this->postJson('/api/golden-schedule', [
            'periodId' => $p->id, 'date' => $p->start_date->toDateString(),
            'participantCode' => 'X-9', 'sectorId' => Sector::where('code', 'DW')->value('id'),
        ])->assertStatus(201);

        $this->deleteJson("/api/scheduling-periods/{$p->id}")->assertOk();
        $this->assertSame(0, GoldenScheduleEntry::count());
    }
}
