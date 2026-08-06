<?php

namespace Tests\Feature;

use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  مدير المركز يقرأ أسماء المرشحين — قرارٌ صريح من صاحب المنصّة
//
//  الاسم يمرّ في أكثر من مسار وبفحصٍ مستقلّ في كلٍّ منها، فلا يكفي أن تُضاف
//  الصلاحية للمصفوفة: نتحقّق من الاستجابة نفسها. ونثبّت في المقابل أنّ من
//  يرصد الدرجة لا يزال بلا اسم — تلك هي الحدود التي يقوم عليها حياد التقييم،
//  ولو انسحب المنح إليها بلا انتباه لسقط الحياد بلا أن يُنبّه أحد.
// ════════════════════════════════════════════════════════════
class CenterManagerNamesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const NAME = 'فهد بن عبدالعزيز الشمري';

    public function test_center_manager_holds_the_permission(): void
    {
        $this->assertContains(
            Permissions::CANDIDATE_VIEW_NAMES,
            Permissions::forRole('CENTER_MANAGER'),
            'مدير المركز فقد رؤية الأسماء'
        );
    }

    public function test_center_manager_sees_the_name_in_the_candidate_record(): void
    {
        [$c] = $this->makeCandidate(['fullName' => self::NAME]);
        $this->actingAsRole('CENTER_MANAGER');

        $res = $this->getJson("/api/candidates/{$c->id}")->assertOk();

        $this->assertSame(self::NAME, $res->json('candidate.name'));
        $this->assertNotNull($res->json('candidate.nationalId'));
    }

    public function test_center_manager_sees_the_name_in_the_cv(): void
    {
        [$c] = $this->makeCandidate(['fullName' => self::NAME]);
        $this->actingAsRole('CENTER_MANAGER');

        $res = $this->getJson("/api/candidates/{$c->id}/cv")->assertOk();

        $this->assertSame(self::NAME, $res->json('cv.name'));
        $this->assertTrue($res->json('cv.canSeeNames'));
    }

    // القائمة بالرمز لكل الأدوار بلا استثناء — حتى حاملي الأسماء. المنح لم
    // يغيّر ذلك، ولو غُيّر لَظهرت الأسماء لكل شاشةٍ مفتوحة في المركز.
    public function test_the_list_stays_code_only_even_for_a_name_holder(): void
    {
        $this->makeCandidate(['fullName' => self::NAME, 'status' => 'approved']);

        foreach (['CENTER_MANAGER', 'SCHEDULER'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/candidates')->assertOk()
                ->assertDontSee(self::NAME, false);
        }
    }

    // الحدّ المقابل: الرصد يبقى بالرمز
    public function test_the_evaluator_and_the_assistant_still_see_no_name(): void
    {
        foreach (['EVALUATOR', 'ASSISTANT'] as $role) {
            $this->assertNotContains(
                Permissions::CANDIDATE_VIEW_NAMES,
                Permissions::forRole($role),
                "الاسم تسرّب إلى {$role} — الرصد يجري بالرمز"
            );
        }
    }

    // المنح لا يمسّ ما لم يُطلَب: سلطات النظام تبقى خارج الدور
    public function test_the_grant_did_not_widen_anything_else(): void
    {
        $perms = Permissions::forRole('CENTER_MANAGER');

        foreach ([Permissions::USER_MANAGE, Permissions::SETTINGS_MANAGE, Permissions::WORKFLOW_MANAGE] as $p) {
            $this->assertNotContains($p, $perms, "سلطة نظام تسرّبت إلى مدير المركز: {$p}");
        }
    }
}
