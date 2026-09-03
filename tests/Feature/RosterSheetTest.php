<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\RosterGroup;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\UserPermissionOverride;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// كشف حضور المشاركين: إسناد المجموعتين، والمستند المطبوع.
class RosterSheetTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const DAY = '2026-07-26';

    // مشارك بجلستَي مقابلة ونقاش في اليوم — المصدر الوحيد لأعمدة المقيّمين الأربعة
    private function participant(string $sectorCode = 'DW'): Candidate
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => $sectorCode]);

        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => self::DAY, 'schedule_time' => '12:30:00',
            'activity' => 'interview',
        ]);
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => self::DAY, 'schedule_time' => '14:30:00',
            'activity' => 'discussion',
        ]);

        return $c;
    }

    // ── الصلاحيات ──

    public function test_assign_requires_roster_manage(): void
    {
        $c = $this->participant();
        $this->actingAsRole('EVALUATOR');

        $this->postJson('/api/roster/assign', [
            'date' => self::DAY, 'group' => 'A', 'candidateIds' => [$c->id],
        ])->assertStatus(403);

        $this->assertSame(0, RosterGroup::count());
    }

    public function test_scheduler_can_assign(): void
    {
        $c = $this->participant();
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/roster/assign', [
            'date' => self::DAY, 'group' => 'A', 'candidateIds' => [$c->id],
        ])->assertOk()->assertJson(['assigned' => 1]);

        $this->assertDatabaseHas('roster_groups', [
            'candidate_id' => $c->id, 'group_letter' => 'A',
        ]);
    }

    public function test_document_requires_schedule_view(): void
    {
        $this->actingAsRole('EVALUATOR');
        $this->get('/api/roster/document?date='.self::DAY)->assertStatus(403);
    }

    public function test_document_is_html(): void
    {
        $this->participant();
        $this->actingAsRole('SCHEDULER');

        $this->get('/api/roster/document?date='.self::DAY)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // ── إعادة الإسناد لا تُنشئ صفّاً ثانياً ──

    public function test_reassigning_moves_instead_of_duplicating(): void
    {
        $c = $this->participant();
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/roster/assign', ['date' => self::DAY, 'group' => 'A', 'candidateIds' => [$c->id]])->assertOk();
        $this->postJson('/api/roster/assign', ['date' => self::DAY, 'group' => 'B', 'candidateIds' => [$c->id]])->assertOk();

        $this->assertSame(1, RosterGroup::where('candidate_id', $c->id)->count());
        $this->assertSame('B', RosterGroup::where('candidate_id', $c->id)->value('group_letter'));
    }

    public function test_group_letter_is_validated(): void
    {
        $c = $this->participant();
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/roster/assign', [
            'date' => self::DAY, 'group' => 'C', 'candidateIds' => [$c->id],
        ])->assertStatus(422);
    }

    // ── الهوية الوطنية: الخيار لا يتجاوز الصلاحية ──

    public function test_national_id_hidden_without_view_names(): void
    {
        $c = $this->participant();
        $nid = $c->national_id;
        // مسؤول العمليات يرى الجدولة ولا يملك candidate.view_names —
        // قراره إجرائي فيعمل بالرمز (كان مدير المركز حتى مُنح الأسماء)
        $this->actingAsRole('OPERATIONS');

        $html = $this->get('/api/roster/document?date='.self::DAY.'&showNationalId=1')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString($nid, $html, 'رقم الهوية ظهر لمن لا يملك صلاحية الأسماء');
        $this->assertStringContainsString($c->participant_code, $html);
    }

    public function test_national_id_shown_when_permitted_and_requested(): void
    {
        $c = $this->participant();
        $nid = $c->national_id;
        $this->actingAsRole('SCHEDULER');

        $html = $this->get('/api/roster/document?date='.self::DAY.'&showNationalId=1')
            ->assertOk()->getContent();
        $this->assertStringContainsString($nid, $html);

        // وبدون الطلب لا تُطبع ولو ملك الصلاحية
        $plain = $this->get('/api/roster/document?date='.self::DAY)->assertOk()->getContent();
        $this->assertStringNotContainsString($nid, $plain);
    }

    // ── النطاق ──

    // الأدوار المحصورة قطاعياً هي EVALUATOR/DISCUSSION_EVAL/ASSISTANT وحدها
    // (مسؤول الجدولة غير محصور). فالحصر يُختبر بمحصورٍ مُنح الصلاحية باستثناء
    // فردي — وهي الحالة التي حذّر منها المتحكّم الأساس: مَن مُنح صلاحية عبر
    // استثناء وهو محصور قطاعياً يجب ألا يرى قطاعاً غير قطاعه.
    private function boundUserWith(string $permission, string $sectorCode = 'DW'): void
    {
        $user = $this->actingAsRole('ASSISTANT', $sectorCode);
        UserPermissionOverride::create([
            'user_id' => $user->id,
            'permission' => $permission,
            'granted' => true,
        ]);
    }

    public function test_sector_bound_user_sees_only_own_sector(): void
    {
        $mine = $this->participant('DW');
        $other = $this->participant('CD');

        $this->boundUserWith(Permissions::SCHEDULE_VIEW, 'DW');
        $html = $this->get('/api/roster/document?date='.self::DAY)->assertOk()->getContent();

        $this->assertStringContainsString($mine->participant_code, $html);
        $this->assertStringNotContainsString($other->participant_code, $html);
    }

    public function test_assign_skips_candidates_outside_scope(): void
    {
        $other = $this->participant('CD');
        $this->boundUserWith(Permissions::ROSTER_MANAGE, 'DW');

        $this->postJson('/api/roster/assign', [
            'date' => self::DAY, 'group' => 'A', 'candidateIds' => [$other->id],
        ])->assertStatus(422);

        $this->assertSame(0, RosterGroup::count());
    }

    // ── أعمدة الأوقات ──

    public function test_sessions_outside_configured_slots_are_flagged(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => self::DAY, 'schedule_time' => '08:05:00',   // خارج القائمة
            'activity' => 'interview',
        ]);

        $this->actingAsRole('SCHEDULER');
        $html = $this->get('/api/roster/document?date='.self::DAY)->assertOk()->getContent();

        $this->assertStringContainsString('خارج الأوقات المعتمدة', $html);
    }

    public function test_slot_headers_follow_the_setting(): void
    {
        Setting::updateOrCreate(['key' => 'schedule.session_times'], ['value' => '08:00,16:45']);
        $this->participant();
        $this->actingAsRole('SCHEDULER');

        $html = $this->get('/api/roster/document?date='.self::DAY)->assertOk()->getContent();

        $this->assertStringContainsString('08:00', $html);
        $this->assertStringContainsString('16:45', $html);
        $this->assertStringNotContainsString('>10:15<', $html);
    }

    // ── إعداد الأوقات ──

    public function test_session_times_require_settings_manage(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->putJson('/api/settings/session-times', ['sessionTimes' => ['09:00']])->assertStatus(403);
    }

    public function test_session_times_reject_bad_format(): void
    {
        $this->actingAsRole('ADMIN');
        $this->putJson('/api/settings/session-times', ['sessionTimes' => ['9am']])->assertStatus(422);
        $this->putJson('/api/settings/session-times', ['sessionTimes' => []])->assertStatus(422);
    }

    public function test_session_times_saved_sorted_and_deduped(): void
    {
        $this->actingAsRole('ADMIN');

        $this->putJson('/api/settings/session-times', ['sessionTimes' => ['14:30', '10:15', '10:15']])
            ->assertOk()
            ->assertJson(['sessionTimes' => ['10:15', '14:30']]);
    }

    // ── الوقت صار إلزامياً عند الجدولة ──

    public function test_schedule_creation_requires_time(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $this->actingAsRole('SCHEDULER');

        $this->postJson('/api/schedules', [
            'candidateId' => $c->id,
            'date' => now()->addDay()->toDateString(),
            'activity' => 'interview',
        ])->assertStatus(422)->assertJsonValidationErrors('time');
    }
}
