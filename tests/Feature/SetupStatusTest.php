<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  إرشاد التهيئة الأولى. خطؤه صنفان: أن يختفي على منصّة ناقصة فيُترك
//  المستخدم أمام شاشاتٍ فارغة لا تقول شيئاً، أو أن يبقى بعد اكتمالها
//  فيصير ضجيجاً دائماً على لوحة تُفتح كل يوم.
// ════════════════════════════════════════════════════════════
class SetupStatusTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::updateOrCreate(['code' => 'ADMIN'], ['name_ar' => 'مدير النظام']);

        return User::create([
            'username' => 'admin', 'full_name' => 'مدير', 'email' => 'a@k.local',
            'password' => 'Secret@12345', 'role_id' => $role->id, 'is_active' => true,
        ]);
    }

    private function evaluator(): User
    {
        $role = Role::updateOrCreate(['code' => 'EVALUATOR'], ['name_ar' => 'مستشار المقابلة']);

        return User::create([
            'username' => 'ev', 'full_name' => 'مستشار', 'email' => 'e@k.local',
            'password' => 'Secret@12345', 'role_id' => $role->id, 'is_active' => true,
        ]);
    }

    public function test_a_fresh_platform_reports_incomplete_setup(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/setup-status');

        $res->assertOk()
            ->assertJson(['applicable' => true, 'complete' => false, 'doneCount' => 0]);
    }

    public function test_the_steps_are_returned_in_dependency_order(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/setup-status');

        // الترتيب ليس تجميلاً: القطاع قبل المشارك لأن رمزه يُشتقّ من بادئته،
        // والكفاءات قبل ربطها بالأنشطة. عرضه مختلطاً يُنتج تهيئةً فاشلة.
        $keys = array_column($res->json('steps'), 'key');
        $this->assertSame(
            ['sectors', 'ranks', 'competencies', 'activity_links', 'staff', 'candidates'],
            $keys
        );
    }

    public function test_ranks_are_optional_because_an_empty_table_falls_back_by_design(): void
    {
        // جدول الرتب يُنشأ فارغاً عمداً، وما دام فارغاً يبقى تصنيف الفئة على
        // المنطق القائم. إعلانه إلزامياً يقول للمدير إن المنصّة غير جاهزة وهي جاهزة.
        $res = $this->actingAs($this->admin())->getJson('/api/setup-status');

        $ranks = collect($res->json('steps'))->firstWhere('key', 'ranks');
        $this->assertFalse($ranks['required']);
    }

    public function test_a_completed_step_is_marked_done_with_its_count(): void
    {
        Sector::create(['code' => 'TS', 'name_ar' => 'قطاع', 'is_military' => false]);

        $res = $this->actingAs($this->admin())->getJson('/api/setup-status');

        $sectors = collect($res->json('steps'))->firstWhere('key', 'sectors');
        $this->assertTrue($sectors['done']);
        $this->assertSame(1, $sectors['count']);
        $this->assertSame(1, $res->json('doneCount'));
    }

    public function test_the_current_user_does_not_count_as_staff(): void
    {
        // منصّةٌ فيها مدير واحد لم تُسلَّم بعد — عدّه موظّفاً يُعلن الخطوة
        // مكتملةً وهي لم تبدأ
        $res = $this->actingAs($this->admin())->getJson('/api/setup-status');

        $staff = collect($res->json('steps'))->firstWhere('key', 'staff');
        $this->assertFalse($staff['done']);
        $this->assertSame(0, $staff['count']);
    }

    public function test_it_reports_complete_once_every_required_step_is_done(): void
    {
        $this->seedRequiredSteps();

        $res = $this->actingAs($this->admin())->getJson('/api/setup-status');

        // المشاركون غير إلزاميين: منصّة مهيّأة بلا مشاركين بعدُ جاهزة للعمل،
        // وإبقاء الإرشاد لأجلهم يجعله لافتةً دائمة على اللوحة.
        $this->assertTrue($res->json('complete'));
        $candidates = collect($res->json('steps'))->firstWhere('key', 'candidates');
        $this->assertFalse($candidates['done']);
        $this->assertFalse($candidates['required']);
    }

    public function test_it_is_not_applicable_to_a_user_who_cannot_perform_the_steps(): void
    {
        $res = $this->actingAs($this->evaluator())->getJson('/api/setup-status');

        $res->assertOk()->assertJson(['applicable' => false]);
        // ولا يُسرّب عدّادات القاعدة لمن لا يملك الإعدادات
        $this->assertNull($res->json('steps'));
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/setup-status')->assertStatus(401);
    }

    private function seedRequiredSteps(): void
    {
        $sector = Sector::create(['code' => 'TS', 'name_ar' => 'قطاع', 'is_military' => false]);
        DB::table('ranks')->insert([
            'label' => 'عميد', 'category' => 'military', 'tier' => 'upper',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cid = DB::table('competencies')->insertGetId([
            'name_ar' => 'القيادة', 'type' => 'leadership', 'max_level' => 5,
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('activity_competency')->insert(['activity' => 'interview', 'competency_id' => $cid]);

        $role = Role::updateOrCreate(['code' => 'EVALUATOR'], ['name_ar' => 'مستشار المقابلة']);
        User::create([
            'username' => 'staff1', 'full_name' => 'موظّف', 'email' => 's@k.local',
            'password' => 'Secret@12345', 'role_id' => $role->id, 'is_active' => true,
            'sector_id' => $sector->id,
        ]);
    }
}
