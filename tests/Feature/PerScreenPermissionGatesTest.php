<?php

namespace Tests\Feature;

use App\Models\UserPermissionOverride;
use App\Security\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  صلاحية كل شاشة تُغلق مسارها هي
//
//  منذ صار الدور يُحرَّر من الشاشة، صار السحب فعلاً يجريه المدير لا قراراً
//  يُتخذ مرّة في الشيفرة. وصلاحيةٌ تُخفي الرابط ولا تُغلق المسار تُعطي المدير
//  يقيناً كاذباً: يسحبها فيظنّ الباب أُغلق، والمسار يستجيب لمن يعرف عنوانه.
//
//  هذه الشاشات الأربع كانت تقبل صلاحيةً عامّة بديلاً عن صلاحيتها — إبقاءً على
//  ما كان يعمل قبل الفصل — فكان الفصل اسماً بلا أثر. هنا يُثبَّت الأثر.
// ════════════════════════════════════════════════════════════
class PerScreenPermissionGatesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // الشاشة ← [الصلاحية التي تحرسها، مسار للقراءة]
    private const GATES = [
        'القيادة التنفيذية: المؤشرات' => [Permissions::ANALYTICS_EXECUTIVE, '/api/analytics/executive'],
        // التبويبان الآخران خلف البوّابة نفسها: سحبُها يُغلق الشاشة كلّها لا واجهتها
        'القيادة التنفيذية: النظرة الشاملة' => [Permissions::ANALYTICS_EXECUTIVE, '/api/analytics/executive/overview'],
        'القيادة التنفيذية: التقارير' => [Permissions::ANALYTICS_EXECUTIVE, '/api/analytics/executive/reports'],
        'التقرير اليومي' => [Permissions::ANALYTICS_DAILY_REPORT, '/api/daily-report'],
    ];

    // سحبُ الصلاحية عن مدير المركز — وهو يملك التحليلات العامّة — يجب أن يُغلق
    // المسار. لو بقي البديل مقبولاً لمرّت هذه الطلبات بـ200.
    public function test_pulling_a_screen_permission_closes_its_endpoint(): void
    {
        foreach (self::GATES as $screen => [$permission, $path]) {
            $user = $this->actingAsRole('CENTER_MANAGER');

            $this->getJson($path)->assertOk();   // بالصلاحية: مفتوح

            UserPermissionOverride::create([
                'user_id' => $user->id, 'permission' => $permission, 'granted' => false,
            ]);
            $user->refresh();

            $this->assertTrue(
                $user->hasPermission(Permissions::ANALYTICS_VIEW),
                'الاختبار بلا معنى إن لم يبقَ معه التحليلات العامّة'
            );

            $this->getJson($path)->assertStatus(403);   // بلا صلاحيته: مغلق ولو ملك العامّة
        }
    }

    // المحادثات: بوّابتها CHAT_VIEW فوق بوّابة الكيان — كانت بوّابة الكيان وحدها
    public function test_pulling_chat_closes_the_thread_endpoint(): void
    {
        $user = $this->actingAsRole('CENTER_MANAGER');

        UserPermissionOverride::create([
            'user_id' => $user->id, 'permission' => Permissions::CHAT_VIEW, 'granted' => false,
        ]);
        $user->refresh();
        $this->assertTrue($user->hasPermission(Permissions::REPORT_VIEW));

        $this->getJson('/api/chat/report/1')->assertStatus(403);
    }

    // خطة التطوير: صلاحيتها لا REPORT_VIEW
    public function test_pulling_the_development_plan_closes_its_endpoint(): void
    {
        [$c] = $this->makeCandidate();
        $user = $this->actingAsRole('CENTER_MANAGER');

        $this->getJson("/api/development-plans/{$c->id}")->assertOk();

        UserPermissionOverride::create([
            'user_id' => $user->id, 'permission' => Permissions::DEVELOPMENT_PLAN_VIEW, 'granted' => false,
        ]);
        $user->refresh();
        $this->assertTrue($user->hasPermission(Permissions::REPORT_VIEW));

        $this->getJson("/api/development-plans/{$c->id}")->assertStatus(403);
    }

    // الوجه الآخر: التضييق لم يُغلق باباً على حامل الصلاحية في المصفوفة —
    // لو نقصت صلاحيةٌ عن دورٍ كان يفتح شاشته لصار العطل صامتاً عنده
    public function test_every_role_that_had_the_screen_still_has_it(): void
    {
        foreach (Permissions::matrix() as $role => $perms) {
            if (in_array('*', $perms, true)) {
                continue;
            }
            if (!in_array(Permissions::ANALYTICS_VIEW, $perms, true)) {
                continue;
            }
            // التقرير اليومي يبقى لكل من يملك التحليلات العامّة — فقدُه عطلٌ صامت.
            // أمّا القيادة التنفيذية فحُصرت في مدير المركز بقرارٍ صريح، ويحرسه
            // الاختبار التالي — فلا تُدرَج هنا وإلا صار الحارسان متناقضين.
            $this->assertContains(Permissions::ANALYTICS_DAILY_REPORT, $perms,
                "{$role} يملك التحليلات العامّة وفقد التقرير اليومي — شاشة تُغلق في وجهه بعد التضييق");
        }

        // خطة التطوير والمحادثات: كل من يقرأ التقارير يملكهما
        foreach (Permissions::matrix() as $role => $perms) {
            if (in_array('*', $perms, true) || !in_array(Permissions::REPORT_VIEW, $perms, true)) {
                continue;
            }
            $this->assertContains(Permissions::DEVELOPMENT_PLAN_VIEW, $perms, "{$role} فقد خطة التطوير");
            $this->assertContains(Permissions::CHAT_VIEW, $perms, "{$role} فقد المحادثات");
        }
    }
}
