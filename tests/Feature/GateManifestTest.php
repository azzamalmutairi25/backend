<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GateManifest;
use App\Models\Notification;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  بيان تصاريح الدخول — يومٌ واحد، وورقةٌ واحدة، واعتمادٌ قبل الطباعة.
//
//  البيان يفتح باب المركز لأسماءٍ بأرقام هوياتهم. فمن يُعدّه ليس من يأذن به،
//  ولا يخرج بلا اعتماد. وحلّ محلّ أربعين ورقةً تُطبع وتُوزَّع وتُفقَد إحداها.
// ════════════════════════════════════════════════════════════
class GateManifestTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function scheduledToday(array $attrs = []): array
    {
        [$c, $a] = $this->makeCandidate(array_merge(['status' => 'scheduled'], $attrs));
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '09:00',
            'activity' => 'interview',
        ]);

        return [$c, $a];
    }

    private function prepare(): int
    {
        $this->actingAsRole('RECEPTIONIST');

        return $this->postJson('/api/gate-manifests', [
            'date' => now()->toDateString(),
            'gateTime' => 'الثامنة والنصف صباحاً',
            'location' => 'البوّابة الرئيسة',
        ])->assertStatus(201)->json('manifest.id');
    }

    // ═══ الإعداد ═══

    public function test_the_manifest_snapshots_the_days_scheduled(): void
    {
        [$c] = $this->scheduledToday();
        $id = $this->prepare();

        $m = GateManifest::findOrFail($id);
        $this->assertSame('draft', $m->status);
        $this->assertTrue($m->candidates->contains('id', $c->id));
    }

    // صفوفُه تُثبَّت لا تُشتقّ: جلسةٌ تُضاف بعد الإعداد لا تُدخِل من لم يُعتمَد
    public function test_a_session_added_later_does_not_enter_the_manifest(): void
    {
        $this->scheduledToday();
        $id = $this->prepare();

        $this->scheduledToday();   // اسمٌ جديد بعد الإعداد

        $this->assertSame(1, GateManifest::findOrFail($id)->candidates()->count(),
            'الاعتماد يقع على ما التُقط');
    }

    public function test_the_gate_time_is_written_by_hand(): void
    {
        $this->scheduledToday();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/gate-manifests', ['date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('errors.gateTime.0', 'اكتب موعد الحضور عند البوّابة — ليتّفق مع خطاب القطاع');
    }

    public function test_a_day_without_scheduled_people_has_no_manifest(): void
    {
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/gate-manifests', [
            'date' => now()->addWeek()->toDateString(), 'gateTime' => '٨:٣٠',
        ])->assertStatus(422)->assertJsonPath('error', 'لا مجدولين في هذا اليوم — لا بيان بلا أسماء');
    }

    public function test_one_manifest_per_day(): void
    {
        $this->scheduledToday();
        $this->prepare();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson('/api/gate-manifests', [
            'date' => now()->toDateString(), 'gateTime' => '٩:٠٠',
        ])->assertStatus(422);
    }

    // ═══ الاعتماد ═══

    public function test_the_manager_approves_what_reception_prepared(): void
    {
        $this->scheduledToday();
        $id = $this->prepare();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/gate-manifests/{$id}/submit")->assertOk();

        $mgr = $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/gate-manifests/{$id}/approve")->assertOk();

        $m = GateManifest::findOrFail($id);
        $this->assertTrue($m->isApproved());
        $this->assertSame($mgr->id, $m->approved_by);
        $this->assertNotNull($m->approved_at);
    }

    // ── من يُعدّ ليس من يأذن ──
    public function test_reception_cannot_approve_its_own_manifest(): void
    {
        $this->scheduledToday();
        $id = $this->prepare();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/gate-manifests/{$id}/submit")->assertOk();
        $this->postJson("/api/gate-manifests/{$id}/approve")->assertStatus(403);

        $this->assertFalse(GateManifest::findOrFail($id)->isApproved());
    }

    public function test_a_draft_is_not_approved_before_it_is_submitted(): void
    {
        $this->scheduledToday();
        $id = $this->prepare();

        $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/gate-manifests/{$id}/approve")->assertStatus(422);
    }

    public function test_submitting_tells_the_manager_and_approving_tells_the_clerk(): void
    {
        $mgr = $this->actingAsRole('CENTER_MANAGER');
        $this->scheduledToday();
        // `actingAsRole` تُنشئ حساباً جديداً كلَّ مرّة — فالمُعِدّ يُلتقَط
        // من الحساب الفاعل نفسه لا من نداءٍ سابق
        $clerk = $this->actingAsRole('RECEPTIONIST');
        $id = $this->postJson('/api/gate-manifests', [
            'date' => now()->toDateString(), 'gateTime' => 'الثامنة والنصف صباحاً',
        ])->assertStatus(201)->json('manifest.id');

        $this->postJson("/api/gate-manifests/{$id}/submit")->assertOk();
        $this->assertTrue(Notification::where('recipient_id', $mgr->id)
            ->where('title', 'بيان تصاريح دخول بانتظار الاعتماد')->exists());

        $this->actingAs($mgr);
        $this->postJson("/api/gate-manifests/{$id}/approve")->assertOk();
        $this->assertTrue(Notification::where('recipient_id', $clerk->id)
            ->where('title', 'اعتُمد بيان تصاريح الدخول')->exists());
    }

    // ═══ الورقة ═══

    // لا يخرج بيانٌ غير معتمَد — الورقة تفتح باب المركز والاعتماد هو الإذن
    public function test_an_unapproved_manifest_is_never_printed(): void
    {
        $this->scheduledToday();
        $id = $this->prepare();

        $this->actingAsRole('RECEPTIONIST');
        $this->getJson("/api/gate-manifests/{$id}/document")
            ->assertStatus(422)
            ->assertJsonPath('error', 'لا يُطبع بيانٌ غير معتمَد — مسوّدة');
    }

    public function test_the_printed_sheet_carries_the_name_the_id_and_the_stamp_but_no_code(): void
    {
        [$c] = $this->scheduledToday(['fullName' => 'عبدالله بن سعد الغامدي', 'code' => 'ZZ7788']);
        $id = $this->prepare();

        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/gate-manifests/{$id}/submit")->assertOk();
        $mgr = $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/gate-manifests/{$id}/approve")->assertOk();

        $this->actingAsRole('RECEPTIONIST');
        $html = $this->get("/api/gate-manifests/{$id}/document")->assertOk()->getContent();

        $this->assertStringContainsString('عبدالله بن سعد الغامدي', $html);
        $this->assertStringContainsString($c->national_id, $html);
        $this->assertStringContainsString('الثامنة والنصف صباحاً', $html);
        // الختم: من أذِن
        $this->assertStringContainsString($mgr->full_name, $html);
        // ولا رمز — أداةٌ داخلية لا يعرفها الحارس
        $this->assertStringNotContainsString('ZZ7788', $html);
    }

    public function test_printing_is_audited_every_time(): void
    {
        $this->scheduledToday();
        $id = $this->prepare();
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/gate-manifests/{$id}/submit")->assertOk();
        $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/gate-manifests/{$id}/approve")->assertOk();

        $this->actingAsRole('RECEPTIONIST');
        $this->get("/api/gate-manifests/{$id}/document")->assertOk();
        $this->get("/api/gate-manifests/{$id}/document")->assertOk();

        $this->assertSame(2, AuditLog::where('action', 'PRINT_GATE_MANIFEST')->count(),
            'إخراج الأسماء وأرقام الهويات يُدقَّق في كل مرّة');
    }

    // ═══ الحراسات ═══

    public function test_an_approved_manifest_is_not_deleted(): void
    {
        $this->scheduledToday();
        $id = $this->prepare();
        $this->actingAsRole('RECEPTIONIST');
        $this->postJson("/api/gate-manifests/{$id}/submit")->assertOk();
        $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/gate-manifests/{$id}/approve")->assertOk();

        $this->actingAsRole('RECEPTIONIST');
        $this->deleteJson("/api/gate-manifests/{$id}")->assertStatus(422);
        $this->assertNotNull(GateManifest::find($id));
    }

    public function test_others_do_not_see_the_manifest(): void
    {
        $this->actingAsRole('EVALUATOR', 'DW');
        $this->getJson('/api/gate-manifests')->assertStatus(403);
    }

    // التصاريح الفردية سقطت — والمسار لم يعد قائماً
    public function test_the_individual_permits_route_is_gone(): void
    {
        $this->actingAsRole('SCHEDULER');
        $this->getJson('/api/schedules/permits')->assertStatus(404);
    }
}
