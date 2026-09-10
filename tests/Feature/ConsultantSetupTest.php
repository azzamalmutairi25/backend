<?php

namespace Tests\Feature;

use App\Models\AssessorAbsence;
use App\Models\PeriodAssessor;
use App\Models\Role;
use App\Models\SchedulingPeriod;
use App\Models\Sector;
use App\Models\TechnicalArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// إعدادات المستشارين — بابٌ ضيّق لمسؤول الجدولة: الرمز والقطاعات والمجالات.
class ConsultantSetupTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function consultant(string $name, string $sectorCode = 'DW', ?string $code = null): User
    {
        return User::create([
            'username' => 'u_'.substr(md5(uniqid('', true)), 0, 8),
            'code' => $code,
            'full_name' => $name,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'EVALUATOR')->value('id'),
            'sector_id' => Sector::where('code', $sectorCode)->value('id'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function area(string $label, array $sectorCodes): TechnicalArea
    {
        $a = TechnicalArea::create(['label_ar' => $label, 'is_active' => true, 'sort_order' => 0]);
        $a->sectors()->sync(Sector::whereIn('code', $sectorCodes)->pluck('id')->all());

        return $a;
    }

    // ═══ العرض ═══

    public function test_the_list_holds_consultants_only_and_sorts_the_coded_first(): void
    {
        $this->consultant('بلا رمز');
        $this->consultant('صاحب الرمز', 'DW', 'K');

        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson('/api/consultants')->assertOk();

        $names = collect($res->json('consultants'))->pluck('fullName');
        $this->assertSame('صاحب الرمز', $names->first(), 'المرقَّم أولاً — والشبكة تُبنى بالرموز');
        $this->assertTrue($names->contains('بلا رمز'));

        // مسؤول الجدولة نفسه ليس مستشاراً — لا يظهر في قائمته
        $roles = collect($res->json('consultants'))->pluck('roleCode')->unique()->all();
        $this->assertSame([], array_diff($roles, User::SECTOR_BOUND_ROLES));
    }

    public function test_the_references_come_with_the_list(): void
    {
        $this->actingAsRole('SCHEDULER');
        $res = $this->getJson('/api/consultants')->assertOk();

        $this->assertNotEmpty($res->json('sectors'), 'القطاعات في الطلب نفسه');
        $this->assertNotEmpty($res->json('areas'), 'والمجالات كذلك — لا ثلاث رحلات لفتح شاشة');
        $this->assertTrue($res->json('canManage'));
    }

    // ما يُعطّل الشبكة يُعَدّ ويُعرَض، لا يُكتشف يوم تُفتح الشبكة فارغة
    public function test_the_gaps_that_break_the_grid_are_counted(): void
    {
        $this->actingAsRole('SCHEDULER');
        $before = $this->getJson('/api/consultants')->json('gaps');

        $ready = $this->consultant('كاملُ الإعداد', 'DW', 'B');
        $ready->technicalAreas()->sync([$this->area('الأمن السيبراني', ['DW'])->id]);
        $this->consultant('ناقصٌ تماماً');

        $after = $this->getJson('/api/consultants')->assertOk()->json('gaps');

        $this->assertSame($before['noCode'] + 1, $after['noCode'], 'من بلا رمزٍ لا عمود له في الشبكة');
        $this->assertSame($before['noAreas'] + 1, $after['noAreas'], 'ومن بلا مجالاتٍ لا يُطابق مشاركاً');
    }

    public function test_the_current_absence_and_panel_count_are_shown(): void
    {
        $k = $this->consultant('الغائب', 'DW', 'W');
        AssessorAbsence::create([
            'user_id' => $k->id, 'reason' => 'leave',
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDays(3)->toDateString(),
        ]);
        $p = SchedulingPeriod::create([
            'name' => 'فترة اللوحة', 'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(), 'status' => 'draft',
        ]);
        PeriodAssessor::create(['period_id' => $p->id, 'user_id' => $k->id,
            'activity' => 'interview', 'seat' => 'evaluator']);

        $this->actingAsRole('SCHEDULER');
        $row = collect($this->getJson('/api/consultants')->json('consultants'))
            ->firstWhere('id', $k->id);

        $this->assertSame('إجازة', $row['absence']['reasonLabel']);
        $this->assertTrue($row['absence']['current'], 'جارٍ الآن لا قادم');
        $this->assertSame(1, $row['periodCount']);
    }

    // الهوية تُعرَض وجوداً لا قيمة — يُطالَب بها ولا تُقرأ من هنا
    public function test_the_national_id_is_reported_as_present_not_as_a_value(): void
    {
        $k = $this->consultant('صاحب الهوية', 'DW', 'N');
        $k->national_id = '1012345674';
        $k->save();

        $this->actingAsRole('SCHEDULER');
        $row = collect($this->getJson('/api/consultants')->json('consultants'))
            ->firstWhere('id', $k->id);

        $this->assertTrue($row['hasNationalId']);
        $this->assertArrayNotHasKey('nationalId', $row);
        $this->assertStringNotContainsString('1012345674',
            $this->getJson('/api/consultants')->getContent());
    }

    // ═══ الحفظ ═══

    public function test_the_scheduler_sets_the_code_the_sectors_and_the_areas(): void
    {
        $k = $this->consultant('مستشارٌ يُعَدّ');
        $extra = Sector::where('code', '!=', 'DW')->firstOrFail();
        $area = $this->area('الاتصالات', ['DW']);

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/consultants/{$k->id}", [
            'code' => 'KZ',
            'sectorIds' => [$extra->id],
            'areaIds' => [$area->id],
        ])->assertOk();

        $k->refresh();
        $this->assertSame('KZ', $k->code);
        $this->assertSame([$area->id], $k->technicalAreas()->pluck('technical_areas.id')->all());
        // الأساسي داخلٌ دائماً وإن لم يُرسَل — فلا يبقى بلا قطاع
        $this->assertContains($k->sector_id, $k->sectorIds());
        $this->assertContains($extra->id, $k->sectorIds());
    }

    public function test_the_primary_sector_is_never_changed_from_here(): void
    {
        $k = $this->consultant('صاحبُ الأساسي');
        $primary = $k->sector_id;
        $other = Sector::where('id', '!=', $primary)->firstOrFail();

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/consultants/{$k->id}", [
            'code' => null, 'sectorIds' => [$other->id], 'areaIds' => [],
        ])->assertOk();

        $this->assertSame($primary, $k->refresh()->sector_id, 'التغطية تُزاد، والأساسي قرارُ إدارة مستخدمين');
    }

    // مجالٌ لقطاعٍ لا يغطّيه لا يصل به إلى مشاركٍ أبداً — يُرفض لا يُحفظ صامتاً
    public function test_an_area_outside_the_consultant_sectors_is_refused(): void
    {
        $k = $this->consultant('مستشار الديوان', 'DW');
        $otherSector = Sector::where('code', '!=', 'DW')->firstOrFail();
        $stray = $this->area('مجالٌ لقطاعٍ آخر', [$otherSector->code]);

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/consultants/{$k->id}", [
            'code' => null, 'sectorIds' => [], 'areaIds' => [$stray->id],
        ])->assertStatus(422)
            ->assertJsonPath('errors.areaIds.0', 'مجالاتٌ خارج قطاعات المستشار: مجالٌ لقطاعٍ آخر');

        $this->assertSame(0, $k->technicalAreas()->count());
    }

    // ويُقبل حين يُوسَّع القطاع في الطلب نفسه — الحالة النهائية هي ما يُفحَص
    public function test_the_same_area_passes_once_its_sector_is_added(): void
    {
        $k = $this->consultant('مستشارٌ يتوسّع', 'DW');
        $otherSector = Sector::where('code', '!=', 'DW')->firstOrFail();
        $area = $this->area('مجالٌ لقطاعٍ آخر', [$otherSector->code]);

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/consultants/{$k->id}", [
            'code' => null, 'sectorIds' => [$otherSector->id], 'areaIds' => [$area->id],
        ])->assertOk();

        $this->assertSame([$area->id], $k->technicalAreas()->pluck('technical_areas.id')->all());
    }

    public function test_a_code_is_not_shared_by_two_consultants(): void
    {
        $this->consultant('الأوّل', 'DW', 'K');
        $second = $this->consultant('الثاني');

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/consultants/{$second->id}", [
            'code' => 'K', 'sectorIds' => [], 'areaIds' => [],
        ])->assertStatus(422)->assertJsonPath('errors.code.0', 'الرمز مستخدَمٌ لمستشارٍ آخر');
    }

    public function test_the_code_shape_is_one_or_two_capital_letters(): void
    {
        $k = $this->consultant('صاحبُ رمزٍ رديء');

        $this->actingAsRole('SCHEDULER');
        foreach (['ك', 'abc', 'k1', 'kk'] as $bad) {
            $this->putJson("/api/consultants/{$k->id}", [
                'code' => $bad, 'sectorIds' => [], 'areaIds' => [],
            ])->assertStatus(422);
        }

        $this->putJson("/api/consultants/{$k->id}", [
            'code' => 'AB', 'sectorIds' => [], 'areaIds' => [],
        ])->assertOk();
    }

    // ═══ الحراسات ═══

    public function test_a_non_consultant_account_is_not_edited_from_here(): void
    {
        $clerk = User::create([
            'username' => 'clerk_'.substr(md5(uniqid('', true)), 0, 6),
            'full_name' => 'موظف إدخال', 'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', 'DATA_ENTRY')->value('id'),
            'is_active' => true, 'must_change_password' => false,
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->putJson("/api/consultants/{$clerk->id}", [
            'code' => 'ZZ', 'sectorIds' => [], 'areaIds' => [],
        ])->assertStatus(422);

        $this->assertNull($clerk->refresh()->code);
    }

    public function test_viewing_needs_scheduling_or_user_management_and_saving_needs_scheduling(): void
    {
        $k = $this->consultant('هدفٌ محميّ');

        // مدير النظام يرى بحكم إدارة المستخدمين
        $this->actingAsRole('ADMIN');
        $this->getJson('/api/consultants')->assertOk();

        // والمقيّم لا يرى ولا يحفظ
        $this->actingAsRole('EVALUATOR', 'DW');
        $this->getJson('/api/consultants')->assertStatus(403);
        $this->putJson("/api/consultants/{$k->id}", [
            'code' => 'QQ', 'sectorIds' => [], 'areaIds' => [],
        ])->assertStatus(403);
    }
}
