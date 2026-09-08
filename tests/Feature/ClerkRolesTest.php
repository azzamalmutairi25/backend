<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SchedulingPeriod;
use App\Models\User;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// الخدمة الخامسة: ثلاثة أدوارٍ تفصل من يُدخل عمّن يعتمد، واعتماد الفترة
// ينتقل من مدير المركز إلى مسؤول الجدولة.
class ClerkRolesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // ═══ الأدوار الثلاثة ═══

    public function test_the_three_clerk_roles_are_seeded_with_their_permissions(): void
    {
        foreach (['DATA_ENTRY', 'SCHEDULE_CLERK', 'ASSESS_CLERK'] as $code) {
            $role = Role::where('code', $code)->first();
            $this->assertNotNull($role, "الدور {$code} غير مبذور");
            $this->assertGreaterThan(0,
                DB::table('role_permissions')->where('role_id', $role->id)->count(),
                "الدور {$code} بلا صلاحيات — حسابٌ يصمت عن سبب عجزه");
        }
    }

    // ── موظّف الإدخال يملأ ولا يبتّ ──
    public function test_the_data_entry_clerk_adds_but_does_not_approve(): void
    {
        [$c] = $this->makeCandidate();
        $this->actingAsRole('DATA_ENTRY');

        // يرى ويحرّر
        $this->getJson("/api/candidates/{$c->id}")->assertOk();

        // ولا يعتمد الترشيح ولا يعدّل الحال الوظيفي
        $this->postJson("/api/candidates/{$c->id}/approve")->assertStatus(403);
        $this->patchJson("/api/candidates/{$c->id}/employment", ['employmentStatus' => 'retired'])
            ->assertStatus(403);
    }

    // ── موظّف الإعداد يبني ويُرسل ولا يعتمد ──
    public function test_the_schedule_clerk_builds_and_submits_but_does_not_approve(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $period = SchedulingPeriod::create([
            'name' => 'فترة '.uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'draft',
        ]);

        $this->actingAsRole('SCHEDULE_CLERK');

        $this->postJson('/api/schedules', [
            'candidateId' => $c->id, 'activity' => 'interview',
            'date' => $period->start_date->toDateString(), 'time' => '10:15',
            'periodId' => $period->id,
        ])->assertStatus(201);

        $this->postJson("/api/scheduling-periods/{$period->id}/submit")->assertOk();
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertStatus(403);

        $this->assertSame('pending_center', $period->fresh()->status);
    }

    // ── موظّف التقييم يراجع ويُرجع، ولا يرى الأسماء ──
    public function test_the_assessment_clerk_reviews_by_code_not_by_name(): void
    {
        $matrix = Permissions::matrix()['ASSESS_CLERK'];

        $this->assertContains(Permissions::REPORT_VIEW, $matrix);
        $this->assertContains(Permissions::REPORT_RETURN, $matrix, 'يُرجع الناقص للمستشار');
        $this->assertNotContains(Permissions::CANDIDATE_VIEW_NAMES, $matrix,
            'مراجعتُه فنّية — تعمل بالرمز لا بالاسم');
    }

    // غير محصورٍ بقطاع: لا يجمع تقارير المركز كلّه وهو محصور
    public function test_the_assessment_clerk_is_not_sector_bound(): void
    {
        $this->assertNotContains('ASSESS_CLERK', User::SECTOR_BOUND_ROLES);
    }

    // ═══ اعتماد الفترة انتقل ═══

    public function test_the_scheduling_officer_approves_and_the_center_manager_does_not(): void
    {
        $matrix = Permissions::matrix();

        $this->assertContains(Permissions::SCHEDULE_APPROVE, $matrix['SCHEDULER']);
        $this->assertNotContains(Permissions::SCHEDULE_APPROVE, $matrix['CENTER_MANAGER'],
            'مدير المركز يطّلع على الفترة ولا يعتمدها');
    }

    public function test_the_center_manager_cannot_approve_a_period(): void
    {
        $period = SchedulingPeriod::create([
            'name' => 'فترة '.uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'pending_center',
        ]);

        $this->actingAsRole('CENTER_MANAGER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertStatus(403);
    }

    // الصلاحية القديمة سُحبت من كل حامل ولم تعد تُعرَض
    public function test_the_retired_center_approval_is_held_by_nobody(): void
    {
        $this->assertSame(0,
            DB::table('role_permissions')
                ->where('permission', Permissions::SCHEDULE_APPROVE_CENTER)->count());

        $this->assertNotContains(Permissions::SCHEDULE_APPROVE_CENTER, Permissions::all(),
            'المتقاعدة لا تظهر في شاشة الأدوار');
    }

    // وصلاحية الاعتماد مستقلّة عن صلاحية البناء — وإلا تعذّر الفصل غداً
    public function test_approval_is_a_separate_permission_from_manage(): void
    {
        $clerk = Permissions::matrix()['SCHEDULE_CLERK'];

        $this->assertContains(Permissions::SCHEDULE_MANAGE, $clerk);
        $this->assertNotContains(Permissions::SCHEDULE_APPROVE, $clerk);
    }
}
