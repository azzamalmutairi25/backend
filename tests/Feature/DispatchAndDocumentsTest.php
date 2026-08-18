<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\DispatchAuthority;
use App\Models\ScheduleDispatch;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// الخطوات الباقية: تصاريح الدخول (٩)، وتسليم الجهات (١١)، وملفّ كل قطاع (١٢).
class DispatchAndDocumentsTest extends TestCase
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

    /** مشارك بفئةٍ وقطاعٍ، مع جلسةٍ في التاريخ المطلوب */
    private function scheduled(string $category, string $sectorCode, string $date, ?int $periodId = null): Candidate
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => $sectorCode]);
        $c->forceFill(['personnel_category' => $category])->save();

        $payload = [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $date, 'time' => '10:15', 'location' => 'قاعة ١',
        ];
        if ($periodId) {
            $payload['periodId'] = $periodId;
        }
        $this->postJson('/api/schedules', $payload)->assertStatus(201);

        return $c->fresh();
    }

    // ── الخطوة ٩: تصاريح الدخول ──

    public function test_permits_render_one_card_per_participant(): void
    {
        $date = now()->addDay()->toDateString();
        $this->actingAsRole('SCHEDULER');
        $a = $this->scheduled('military', 'DW', $date);
        $this->scheduled('civilian', 'DW', $date);

        $html = $this->get("/api/schedules/permits?date={$date}")->assertOk()->getContent();

        $this->assertStringContainsString('تصريح دخول', $html);
        $this->assertSame(2, substr_count($html, 'class="permit"'), 'تصريحٌ لكل مشارك');
        $this->assertStringContainsString($a->participant_code, $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PRINT_ENTRY_PERMITS']);
    }

    public function test_a_participant_with_two_sessions_gets_one_permit(): void
    {
        $date = now()->addDay()->toDateString();
        $this->actingAsRole('SCHEDULER');
        $c = $this->scheduled('civilian', 'DW', $date);

        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'discussion',
            'date' => $date, 'time' => '12:30',
        ])->assertStatus(201);

        $html = $this->get("/api/schedules/permits?date={$date}")->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="permit"'), 'التصريح يُقدَّم مرّة واحدة عند البوّابة');
        // وأبكر وقتٍ هو موعد الحضور
        $this->assertStringContainsString('10:15', $html);
    }

    public function test_the_name_needs_both_a_request_and_the_permission(): void
    {
        $date = now()->addDay()->toDateString();
        $this->actingAsRole('SCHEDULER');   // يملك candidate.view_names
        $c = $this->scheduled('civilian', 'DW', $date);

        // بلا طلبٍ صريح: الرمز وحده
        $plain = $this->get("/api/schedules/permits?date={$date}")->assertOk()->getContent();
        $this->assertStringNotContainsString($c->full_name, $plain);

        // وبطلبٍ صريح ممّن يملك الصلاحية: يظهر
        $named = $this->get("/api/schedules/permits?date={$date}&showName=1")->assertOk()->getContent();
        $this->assertStringContainsString($c->full_name, $named);

        // ومن لا يملكها لا يراه ولو طلب
        $this->actingAsRole('OPERATIONS');   // schedule.view بلا candidate.view_names
        $denied = $this->get("/api/schedules/permits?date={$date}&showName=1")->assertOk()->getContent();
        $this->assertStringNotContainsString($c->full_name, $denied);
    }

    // ── الخطوة ١٢: ملفّ لكل قطاع ──

    public function test_an_unbound_manager_can_ask_for_one_sector(): void
    {
        $date = now()->addDay()->toDateString();
        $this->actingAsRole('SCHEDULER');
        $dw = $this->scheduled('civilian', 'DW', $date);
        $ms = $this->scheduled('civilian', 'MS', $date);

        $sectorId = Sector::where('code', 'DW')->value('id');
        $html = $this->get("/api/roster/document?date={$date}&sectorId={$sectorId}")->assertOk()->getContent();

        $this->assertStringContainsString($dw->participant_code, $html);
        $this->assertStringNotContainsString($ms->participant_code, $html, 'قطاعٌ واحد لا الكل');
    }

    public function test_a_sector_bound_reader_stays_in_their_own_sector(): void
    {
        $date = now()->addDay()->toDateString();
        $this->actingAsRole('SCHEDULER');
        $dw = $this->scheduled('civilian', 'DW', $date);
        $ms = $this->scheduled('civilian', 'MS', $date);

        $other = Sector::where('code', 'MS')->value('id');
        $bound = $this->actingAsRole('EVALUATOR', 'DW');
        \App\Models\UserPermissionOverride::create([
            'user_id' => $bound->id, 'permission' => 'schedule.view',
            'granted' => true, 'created_by' => $bound->id,
        ]);
        $this->actingAs($bound->fresh());

        // طلبَ قطاعاً غير قطاعه — يُشدّ إلى قطاعه لا يُوسَّع إليه
        $html = $this->get("/api/roster/document?date={$date}&sectorId={$other}")->assertOk()->getContent();
        $this->assertStringContainsString($dw->participant_code, $html);
        $this->assertStringNotContainsString($ms->participant_code, $html);
    }

    public function test_roster_sectors_lists_only_sectors_that_have_sessions(): void
    {
        $date = now()->addDay()->toDateString();
        $this->actingAsRole('SCHEDULER');
        $this->scheduled('civilian', 'DW', $date);
        $this->scheduled('civilian', 'DW', $date);
        $this->scheduled('military', 'MS', $date);

        $rows = $this->getJson("/api/roster/sectors?date={$date}")->assertOk()->json('sectors');

        $this->assertCount(2, $rows, 'القطاع الخالي لا يُعرض');
        $byName = collect($rows)->keyBy('sectorName');
        $this->assertSame(2, $byName['ديوان الوزارة']['count'] ?? null);
    }

    // ── الخطوة ١١: تسليم الجهات ──

    public function test_the_split_follows_the_personnel_category_not_the_sector(): void
    {
        $p = $this->period();
        $date = $p->start_date->toDateString();
        $this->actingAsRole('SCHEDULER');

        // القطاع نفسه، فئتان مختلفتان — القطاع لا يقرّر الجهة
        $mil = $this->scheduled('military', 'DW', $date, $p->id);
        $civ = $this->scheduled('civilian', 'DW', $date, $p->id);
        $con = $this->scheduled('contractor', 'DW', $date, $p->id);

        $res = $this->getJson("/api/dispatch/preview?periodId={$p->id}")->assertOk();
        $byName = collect($res->json('authorities'))->keyBy('authorityName');

        $military = $byName['وكالة الشؤون العسكرية'];
        $hr = $byName['الموارد البشرية'];

        $this->assertSame(1, $military['count']);
        $this->assertSame($mil->participant_code, $military['rows'][0]['code']);

        // المتعاقد مع المدني في الموارد البشرية — افتراضٌ ظاهر يُغيَّر بتعديل صفّ
        $this->assertSame(2, $hr['count']);
        $codes = collect($hr['rows'])->pluck('code')->all();
        $this->assertContains($civ->participant_code, $codes);
        $this->assertContains($con->participant_code, $codes);
    }

    public function test_the_authority_category_map_is_data_not_code(): void
    {
        $p = $this->period();
        $date = $p->start_date->toDateString();
        $this->actingAsRole('SCHEDULER');
        $con = $this->scheduled('contractor', 'DW', $date, $p->id);

        // ينقل المركز المتعاقدين إلى وكالة الشؤون العسكرية بتعديل صفّ
        DispatchAuthority::where('code', 'MILITARY_AFFAIRS')->update(['categories' => 'military,contractor']);
        DispatchAuthority::where('code', 'HR')->update(['categories' => 'civilian']);

        $byName = collect($this->getJson("/api/dispatch/preview?periodId={$p->id}")->assertOk()->json('authorities'))
            ->keyBy('authorityName');

        $this->assertSame(1, $byName['وكالة الشؤون العسكرية']['count']);
        $this->assertSame(0, $byName['الموارد البشرية']['count']);
    }

    public function test_sending_writes_a_record_with_a_matching_checksum(): void
    {
        $p = $this->period();
        $date = $p->start_date->toDateString();
        $this->actingAsRole('SCHEDULER');
        $this->scheduled('military', 'DW', $date, $p->id);

        $authority = DispatchAuthority::where('code', 'MILITARY_AFFAIRS')->first();

        $this->actingAsRole('CENTER_MANAGER');
        $res = $this->post('/api/dispatch/send', ['authorityId' => $authority->id, 'periodId' => $p->id])
            ->assertOk();

        $csv = $res->getContent();
        $row = ScheduleDispatch::first();

        $this->assertNotNull($row);
        $this->assertSame(1, $row->rows_count);
        $this->assertSame(hash('sha256', $csv), $row->checksum, 'البصمة بصمةُ ما خرج فعلاً');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM كي تفتحه Excel بالعربية');
        $this->assertDatabaseHas('audit_logs', ['action' => 'SEND_SCHEDULE_DISPATCH']);
    }

    public function test_sending_needs_the_dispatch_permission(): void
    {
        $p = $this->period();
        $date = $p->start_date->toDateString();
        $this->actingAsRole('SCHEDULER');
        $this->scheduled('military', 'DW', $date, $p->id);
        $authority = DispatchAuthority::where('code', 'MILITARY_AFFAIRS')->first();

        // مسؤول الجدولة يرى ولا يُسلّم
        $this->getJson("/api/dispatch/preview?periodId={$p->id}")->assertOk();
        $this->post('/api/dispatch/send', ['authorityId' => $authority->id, 'periodId' => $p->id])
            ->assertStatus(403);
    }

    public function test_an_empty_dispatch_is_refused(): void
    {
        $p = $this->period();
        $authority = DispatchAuthority::where('code', 'MILITARY_AFFAIRS')->first();

        $this->actingAsRole('CENTER_MANAGER');
        $this->post('/api/dispatch/send', ['authorityId' => $authority->id, 'periodId' => $p->id])
            ->assertStatus(422);
        $this->assertSame(0, ScheduleDispatch::count());
    }

    public function test_a_classified_participant_is_absent_from_the_preview(): void
    {
        $p = $this->period();
        $date = $p->start_date->toDateString();

        $this->actingAsRole('ADMIN');
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW', 'classification' => 'secret']);
        $c->forceFill(['personnel_category' => 'military'])->save();
        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $date, 'time' => '10:15', 'periodId' => $p->id,
        ])->assertStatus(201);

        $this->actingAsRole('SCHEDULER');   // بلا candidate.view_classified
        $byName = collect($this->getJson("/api/dispatch/preview?periodId={$p->id}")->assertOk()->json('authorities'))
            ->keyBy('authorityName');
        $this->assertSame(0, $byName['وكالة الشؤون العسكرية']['count']);
    }

    public function test_the_receipt_carries_the_checksum(): void
    {
        $p = $this->period();
        $date = $p->start_date->toDateString();
        $this->actingAsRole('SCHEDULER');
        $this->scheduled('military', 'DW', $date, $p->id);
        $authority = DispatchAuthority::where('code', 'MILITARY_AFFAIRS')->first();

        $this->actingAsRole('CENTER_MANAGER');
        $this->post('/api/dispatch/send', ['authorityId' => $authority->id, 'periodId' => $p->id])->assertOk();
        $row = ScheduleDispatch::first();

        $html = $this->get("/api/dispatch/document?dispatchId={$row->id}")->assertOk()->getContent();
        $this->assertStringContainsString('محضر تسليم', $html);
        $this->assertStringContainsString($row->checksum, $html);
        $this->assertStringContainsString('وكالة الشؤون العسكرية', $html);
    }

    // ── سير العمل: الخطوة ١١ صارت آلية ──

    public function test_the_dispatch_step_completes_only_when_every_owed_authority_is_served(): void
    {
        $p = $this->period();
        $date = $p->start_date->toDateString();
        $this->actingAsRole('SCHEDULER');
        $this->scheduled('military', 'DW', $date, $p->id);
        $this->scheduled('civilian', 'DW', $date, $p->id);

        $byKey = fn ($res, $k) => collect($res->json('steps'))->firstWhere('autoKey', $k);
        $this->assertSame('pending', $byKey($this->getJson("/api/scheduling-periods/{$p->id}/workflow"), 'period.dispatched')['status']);

        $this->actingAsRole('CENTER_MANAGER');
        $mil = DispatchAuthority::where('code', 'MILITARY_AFFAIRS')->first();
        $hr = DispatchAuthority::where('code', 'HR')->first();

        $this->post('/api/dispatch/send', ['authorityId' => $mil->id, 'periodId' => $p->id])->assertOk();
        // جهةٌ واحدة لا تكفي ما دامت الأخرى لها مشاركون
        $this->assertSame('pending', $byKey($this->getJson("/api/scheduling-periods/{$p->id}/workflow"), 'period.dispatched')['status']);

        $this->post('/api/dispatch/send', ['authorityId' => $hr->id, 'periodId' => $p->id])->assertOk();
        $this->assertSame('done', $byKey($this->getJson("/api/scheduling-periods/{$p->id}/workflow"), 'period.dispatched')['status']);
    }

    public function test_an_authority_with_no_participants_is_not_awaited(): void
    {
        $p = $this->period();
        $date = $p->start_date->toDateString();
        $this->actingAsRole('SCHEDULER');
        $this->scheduled('civilian', 'DW', $date, $p->id);   // لا عسكريين في هذه الدورة

        $this->actingAsRole('CENTER_MANAGER');
        $hr = DispatchAuthority::where('code', 'HR')->first();
        $this->post('/api/dispatch/send', ['authorityId' => $hr->id, 'periodId' => $p->id])->assertOk();

        $byKey = fn ($res, $k) => collect($res->json('steps'))->firstWhere('autoKey', $k);
        $this->assertSame(
            'done',
            $byKey($this->getJson("/api/scheduling-periods/{$p->id}/workflow"), 'period.dispatched')['status'],
            'دورةٌ بلا عسكريين لا تقف على وكالة الشؤون العسكرية'
        );
    }
}
