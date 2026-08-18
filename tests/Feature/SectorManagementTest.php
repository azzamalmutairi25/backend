<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// إدارة القطاعات القابلة للتعديل: إضافة/تعديل الاسم/حذف — بصلاحية إدارة الإعدادات.
class SectorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(): User
    {
        return User::create([
            'username' => 'adm_' . substr(md5(uniqid('', true)), 0, 6), 'full_name' => 'مدير النظام',
            'password' => 'Kafaat@2026', 'role_id' => Role::where('code', 'ADMIN')->value('id'),
            'user_type' => 'external', 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    public function test_add_sector(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/sectors', ['code' => 'AV', 'nameAr' => 'الطيران'])
            ->assertCreated();
        $this->assertDatabaseHas('sectors', ['code' => 'AV', 'name_ar' => 'الطيران']);
    }

    public function test_add_sector_requires_settings_manage(): void
    {
        $this->actingAsRole('EVALUATOR', 'DW'); // لا يملك settings.manage
        $this->postJson('/api/sectors', ['code' => 'AV', 'nameAr' => 'الطيران'])->assertStatus(403);
    }

    public function test_duplicate_code_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/sectors', ['code' => 'DW', 'nameAr' => 'مكرر'])->assertStatus(422);
    }

    public function test_duplicate_prefix_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/sectors', ['code' => 'AV', 'nameAr' => 'أ', 'participantPrefix' => 'XY'])->assertCreated();
        $this->postJson('/api/sectors', ['code' => 'BV', 'nameAr' => 'ب', 'participantPrefix' => 'XY'])->assertStatus(422);
    }

    public function test_rename_sector(): void
    {
        Sanctum::actingAs($this->admin());
        $s = Sector::where('code', 'DW')->first();
        $this->putJson("/api/sectors/{$s->id}", ['nameAr' => 'ديوان الوزارة والفروع'])->assertOk();
        $this->assertDatabaseHas('sectors', ['id' => $s->id, 'name_ar' => 'ديوان الوزارة والفروع']);
    }

    // ── الاسم الرسمي الكامل ──
    // يُحفظ ويُقرأ مستقلاً عن المعروض، ولا يُمحى بطلبٍ لا يذكره أصلاً
    public function test_full_official_name_saved_and_returned(): void
    {
        Sanctum::actingAs($this->admin());
        $s = Sector::where('code', 'PP')->first();

        $this->putJson("/api/sectors/{$s->id}", [
            'nameAr' => 'الجوازات', 'fullNameAr' => 'المديرية العامة للجوازات — تعديل',
        ])->assertOk();

        $row = collect($this->getJson('/api/sectors')->assertOk()->json('sectors'))
            ->firstWhere('code', 'PP');
        $this->assertSame('المديرية العامة للجوازات — تعديل', $row['fullNameAr']);

        // طلبٌ بلا الحقل (نسخة واجهة أقدم) لا يمسح الاسم الرسمي
        $this->putJson("/api/sectors/{$s->id}", ['nameAr' => 'الجوازات'])->assertOk();
        $this->assertSame('المديرية العامة للجوازات — تعديل', $s->fresh()->full_name_ar);
    }

    // ── القطاع مسمّى فقط ──
    // لا يحمل تصنيفاً عسكرياً/مدنياً: الفئة صفةُ المشارك، فالقطاع الواحد
    // يعمل فيه الأصناف الثلاثة معاً.
    public function test_sector_carries_no_personnel_classification(): void
    {
        Sanctum::actingAs($this->admin());
        $row = collect($this->getJson('/api/sectors')->assertOk()->json('sectors'))
            ->firstWhere('code', 'PS');

        $this->assertArrayNotHasKey('isMilitary', $row);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('sectors', 'is_military'));
    }

    // ما يحرّره المدير من الشاشة لا يدهسه بذرٌ لاحق
    public function test_edited_name_survives_reseed(): void
    {
        Sanctum::actingAs($this->admin());
        $s = Sector::where('code', 'PS')->first();
        $this->putJson("/api/sectors/{$s->id}", ['nameAr' => 'الأمن العام والمرور'])->assertOk();

        // إعادة البذر تُستدعى بدالّتها لا بـ$this->seed(): استدعاء db:seed عبر
        // Artisan داخل اختبارٍ يستعمل RefreshDatabase يُصفّر حالة الترحيل، فتُسقط
        // الاختباراتُ التاليةُ الجداولَ وتنهار بقيّة الحزمة بـ«relation does not exist».
        \App\Data\MoiSectors::sync();
        $this->assertSame('الأمن العام والمرور', $s->fresh()->name_ar, 'البذر دهس اسماً حرّره المدير');
    }

    public function test_moi_sectors_are_seeded_with_official_names(): void
    {
        Sanctum::actingAs($this->admin());

        foreach (\App\Data\MoiSectors::rows() as $row) {
            $this->assertDatabaseHas('sectors', [
                'code' => $row['code'],
                'name_ar' => $row['name_ar'],
                'full_name_ar' => $row['full_name_ar'],
                'participant_prefix' => $row['code'],
            ]);
        }

        // القطاعات التجريبية الثمانية لم تعد تُبذر
        foreach (array_keys(\App\Data\MoiSectors::LEGACY_MAP) as $legacy) {
            $this->assertDatabaseMissing('sectors', ['code' => $legacy]);
        }
    }

    public function test_cannot_delete_sector_with_candidates(): void
    {
        Sanctum::actingAs($this->admin());
        $this->makeCandidate(['sectorCode' => 'DW']);
        $s = Sector::where('code', 'DW')->first();
        $this->deleteJson("/api/sectors/{$s->id}")->assertStatus(422);
    }

    public function test_delete_empty_sector(): void
    {
        Sanctum::actingAs($this->admin());
        $s = Sector::create(['code' => 'ZZ', 'name_ar' => 'مؤقت', 'participant_prefix' => 'ZZ']);
        $this->deleteJson("/api/sectors/{$s->id}")->assertOk();
        $this->assertDatabaseMissing('sectors', ['id' => $s->id]);
    }

    // ── أعداد المرتبطين: بها تعرف الشاشة أيّ قطاع يُحذف وأيّه محمي ──
    // وهي أرقام ترسم حجم كل قطاع، فتُعرض لمدير الإعدادات وحده كالبادئة.
    public function test_link_counts_are_returned_to_settings_manager(): void
    {
        $this->makeCandidate(['sectorCode' => 'DW']);
        $this->makeCandidate(['sectorCode' => 'DW']);
        $this->actingAsRole('EVALUATOR', 'PR');   // مستخدم مربوط بقطاع HO

        Sanctum::actingAs($this->admin());
        $rows = collect($this->getJson('/api/sectors')->assertOk()->json('sectors'));

        $this->assertSame(2, $rows->firstWhere('code', 'DW')['candidateCount']);
        $this->assertSame(1, $rows->firstWhere('code', 'PR')['userCount']);
        $this->assertSame(0, $rows->firstWhere('code', 'PP')['candidateCount']);
    }

    public function test_link_counts_are_hidden_from_others(): void
    {
        $this->makeCandidate(['sectorCode' => 'DW']);
        $this->actingAsRole('EVALUATOR', 'DW');   // بلا settings.manage

        $row = collect($this->getJson('/api/sectors')->assertOk()->json('sectors'))
            ->firstWhere('code', 'DW');

        $this->assertArrayNotHasKey('candidateCount', $row);
        $this->assertArrayNotHasKey('userCount', $row);
        $this->assertArrayNotHasKey('participantPrefix', $row);
    }
}
