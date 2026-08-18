<?php

namespace Tests\Feature;

use App\Models\DevelopmentPlanItem;
use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// القيادة التنفيذية للمركز — التبويبان الجديدان:
//   نظرة شاملة على أبواب المنصّة (إلا الإعدادات) + لوحة التقارير التنفيذية.
// البوابة واحدة لثلاثتها: analytics.executive.
class ExecutiveLeadershipTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function evaluator(): User
    {
        return User::create([
            'username' => 'ev_' . substr(md5(uniqid('', true)), 0, 6), 'full_name' => 'مقيّم',
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', 'DW')->value('id'), 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    // ── البوابة ──

    public function test_overview_and_reports_require_the_executive_permission(): void
    {
        $this->actingAsRole('EVALUATOR', 'DW'); // لا يملك analytics.executive
        $this->getJson('/api/analytics/executive/overview')->assertStatus(403);
        $this->getJson('/api/analytics/executive/reports')->assertStatus(403);
    }

    // ── الشاشة صلاحية مدير المركز وحده ──
    //
    // صورةُ المركز كلّه — الجدولة والاستقبال والحضور والفريق وسجل التدقيق —
    // لا صورةُ إدارةٍ فيه. ومن يُسأل عن المركز أمام الجهة هو من يقرؤها.
    public function test_the_screen_belongs_to_the_center_manager_alone(): void
    {
        $holders = [];
        foreach (Permissions::matrix() as $role => $perms) {
            if (in_array('*', $perms, true) || in_array(Permissions::ANALYTICS_EXECUTIVE, $perms, true)) {
                $holders[] = $role;
            }
        }

        // مدير النظام بـ'*' ومدير المركز صراحةً — ولا ثالث
        $this->assertEqualsCanonicalizing(['ADMIN', 'CENTER_MANAGER'], $holders);
    }

    // الوجه العملي: الدوران اللذان كانا يفتحانها لم يعودا، ولم يفقدا عملهما
    public function test_assessment_and_development_managers_lost_it_but_kept_their_analytics(): void
    {
        foreach (['ASSESS_MANAGER', 'DEV_MANAGER'] as $role) {
            $user = $this->actingAsRole($role);

            $this->getJson('/api/analytics/executive')->assertStatus(403);
            $this->getJson('/api/analytics/executive/overview')->assertStatus(403);
            $this->getJson('/api/analytics/executive/reports')->assertStatus(403);

            // ما يخصّ إدارتهما باقٍ — التضييق لم يُطفئ عملهما
            $this->assertTrue($user->hasPermission(Permissions::ANALYTICS_VIEW), "{$role} فقد التحليلات العامّة");
            $this->assertTrue($user->hasPermission(Permissions::ANALYTICS_DAILY_REPORT), "{$role} فقد التقرير اليومي");
            $this->getJson('/api/analytics/dashboard')->assertOk();
            $this->getJson('/api/daily-report')->assertOk();
        }
    }

    // المصفوفة بذرةٌ لا قفل: صاحب المنصّة يعيدها لمن يشاء من شاشة الأدوار
    public function test_it_can_be_granted_back_from_the_roles_screen(): void
    {
        $user = $this->actingAsRole('ASSESS_MANAGER');
        $this->getJson('/api/analytics/executive/overview')->assertStatus(403);

        UserPermissionOverride::create([
            'user_id' => $user->id, 'permission' => Permissions::ANALYTICS_EXECUTIVE, 'granted' => true,
        ]);
        $user->refresh();

        $this->getJson('/api/analytics/executive/overview')->assertOk();
    }

    // إغلاقُ التبويبين عند سحب الصلاحية مُثبَّتٌ في PerScreenPermissionGatesTest
    // مع بقيّة الشاشات — الحارس واحدٌ فلا يُختبر مرّتين.

    // ── النظرة الشاملة ──

    public function test_overview_covers_every_module_and_excludes_settings(): void
    {
        $this->actingAsRole('CENTER_MANAGER');

        $res = $this->getJson('/api/analytics/executive/overview')->assertOk();
        $sections = $res->json('sections');

        $keys = array_column($sections, 'key');
        foreach ([
            'candidates', 'waves', 'sessions', 'reception', 'attendance', 'evaluation',
            'measurement', 'reports', 'development_plans', 'competencies',
            'update_requests', 'people', 'audit',
        ] as $expected) {
            $this->assertContains($expected, $keys, "القسم «{$expected}» غائب عن النظرة الشاملة");
        }

        // الإعدادات خارج الشاشة بقرارٍ صريح — ضبط النظام سلطةٌ لا اطّلاع
        $this->assertNotContains('settings', $keys);

        // كل قسم بالشكل نفسه كي يعرضه مُصيِّرٌ واحد
        foreach ($sections as $s) {
            $this->assertArrayHasKey('label', $s);
            $this->assertArrayHasKey('icon', $s);
            $this->assertArrayHasKey('metrics', $s);
            $this->assertArrayHasKey('bars', $s);
            $this->assertNotEmpty($s['metrics'], "القسم «{$s['key']}» بلا مؤشرات");
        }
    }

    public function test_overview_counts_reflect_real_rows(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $ev = $this->evaluator();

        [$c1, $a1] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'completed']);
        [$c2, $a2] = $this->makeCandidate(['sectorCode' => 'MS', 'status' => 'scheduled']);
        Evaluation::create([
            'candidate_id' => $c1->id, 'assessment_id' => $a1->id, 'evaluator_id' => $ev->id,
            'activity' => 'interview', 'status' => 'approved',
        ]);
        DevelopmentPlanItem::create([
            'candidate_id' => $c1->id, 'assessment_id' => $a1->id,
            'area' => 'التخطيط', 'action' => 'برنامج تدريبي',
            'target_date' => now()->subDays(5)->toDateString(), 'status' => 'pending', 'created_by' => null,
        ]);

        $sections = collect($this->getJson('/api/analytics/executive/overview')->assertOk()->json('sections'))
            ->keyBy('key');

        $metric = fn ($key, $label) => collect($sections[$key]['metrics'])->firstWhere('label', $label)['value'];

        $this->assertSame(2, $metric('candidates', 'الإجمالي'));
        $this->assertSame(1, $metric('evaluation', 'معتمدة'));
        // بندٌ مضى موعده وهو غير منجَز — يُحسب متأخّراً
        $this->assertSame(1, $metric('development_plans', 'متأخّرة'));
    }

    // مَن لا يقرأ المصنَّف لا تُحسب له صفوفه ولو في عدّادٍ مجمّع.
    // الطرفان مدير مركز — واحدهما مسحوبةٌ عنه رؤية المصنَّف باستثناء فردي،
    // فالفرق بينهما هو التصنيف وحده لا الدور.
    public function test_overview_respects_classification_scope(): void
    {
        $this->makeCandidate(['sectorCode' => 'DW', 'classification' => 'normal']);
        $this->makeCandidate(['sectorCode' => 'DW', 'classification' => 'secret']);

        $total = fn () => collect(collect($this->getJson('/api/analytics/executive/overview')->json('sections'))
            ->keyBy('key')['candidates']['metrics'])->firstWhere('label', 'الإجمالي')['value'];

        $this->actingAsRole('CENTER_MANAGER');   // يقرأ المصنَّف
        $this->assertSame(2, $total());

        $narrow = $this->actingAsRole('CENTER_MANAGER');
        UserPermissionOverride::create([
            'user_id' => $narrow->id, 'permission' => Permissions::CANDIDATE_VIEW_CLASSIFIED, 'granted' => false,
        ]);
        $narrow->refresh();
        $this->assertSame(1, $total(), 'المصنَّف دخل عدّاد من لا يقرؤه');
    }

    // ── لوحة التقارير ──

    public function test_reports_board_returns_pipeline_aging_and_rows(): void
    {
        $this->actingAsRole('CENTER_MANAGER');

        [$c1, $a1] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'completed', 'code' => 'EXEC001']);
        [$c2, $a2] = $this->makeCandidate(['sectorCode' => 'MS', 'status' => 'assessed', 'code' => 'EXEC002']);

        FinalReport::create([
            'candidate_id' => $c1->id, 'assessment_id' => $a1->id, 'status' => 'approved',
            'behavioral_fit' => 80, 'technical_fit' => 70, 'recommendation' => 'يوصى به',
            'executive_summary' => 'ملخّص', 'created_by' => null,
        ]);
        $pending = FinalReport::create([
            'candidate_id' => $c2->id, 'assessment_id' => $a2->id, 'status' => 'pending_manager',
            'behavioral_fit' => 60, 'technical_fit' => 50, 'created_by' => null,
        ]);
        // يُقدَّم عمره عشرة أيام كي يُقاس الانتظار لا يُفترض
        $pending->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

        $res = $this->getJson('/api/analytics/executive/reports')->assertOk();
        $res->assertJsonStructure([
            'kpis' => ['total', 'approved', 'inChain', 'returned', 'avgReadiness', 'execSummaries'],
            'pipeline', 'aging', 'byRecommendation',
            'recent' => [['id', 'code', 'sector', 'readiness', 'statusLabel', 'updatedAt']],
        ]);

        $this->assertSame(2, $res->json('kpis.total'));
        $this->assertSame(1, $res->json('kpis.approved'));
        $this->assertSame(1, $res->json('kpis.inChain'));
        $this->assertSame(1, $res->json('kpis.execSummaries'));
        $this->assertEquals(75, $res->json('kpis.avgReadiness'));   // (80+70)/2

        $aging = collect($res->json('aging'))->firstWhere('status', 'pending_manager');
        $this->assertNotNull($aging);
        $this->assertSame(10, $aging['oldestDays']);
    }

    // شاشة اطّلاع تعمل بالرمز: الاسم لا يخرج منها ولو ملك القارئ صلاحيته
    public function test_reports_board_never_leaks_candidate_names(): void
    {
        $this->actingAsRole('CENTER_MANAGER');   // يملك candidate.view_names
        [$c, $a] = $this->makeCandidate([
            'sectorCode' => 'DW', 'status' => 'completed',
            'fullName' => 'اسمٌ لا يجوز أن يظهر', 'code' => 'EXEC900',
        ]);
        FinalReport::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'status' => 'approved',
            'behavioral_fit' => 90, 'technical_fit' => 80, 'created_by' => null,
        ]);

        $res = $this->getJson('/api/analytics/executive/reports')->assertOk();
        $res->assertDontSee('اسمٌ لا يجوز أن يظهر');
        $this->assertSame('EXEC900', $res->json('recent.0.code'));
    }
}
