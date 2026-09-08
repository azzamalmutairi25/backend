<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sector;
use App\Models\TechnicalArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// الخدمة الثالثة: المجال يُنسَب لقطاعاته، والمستشار يُوسَم بالمجالات نفسها،
// وله رمزٌ وهوية مشفَّرة. والمطابقة بينهما تقاطعٌ صريح لا بحثٌ نصّيّ.
class ConsultantAreasAndIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function area(string $label, array $sectorCodes = ['DW']): TechnicalArea
    {
        $a = TechnicalArea::create(['label_ar' => $label, 'is_active' => true, 'sort_order' => 0]);
        $a->sectors()->sync(Sector::whereIn('code', $sectorCodes)->pluck('id')->all());

        return $a;
    }

    // ═══ المجال ↔ القطاعات ═══

    public function test_the_seeded_areas_are_attached_to_every_sector(): void
    {
        // تصنيفٌ مركزيّ لا قطاعيّ حتى يوزّعه ملفّ المركز — فلا مجالَ بلا قطاع
        $sectors = Sector::count();
        $orphans = TechnicalArea::whereDoesntHave('sectors')->count();

        $this->assertSame(0, $orphans, 'مجالٌ بلا قطاع لا يظهر في أي نموذج');
        $this->assertGreaterThan(0, $sectors);
    }

    public function test_creating_an_area_requires_at_least_one_sector(): void
    {
        $this->actingAsRole('ADMIN');

        $this->postJson('/api/technical-areas', ['label' => 'مجالٌ يتيم'])
            ->assertStatus(422);

        $this->postJson('/api/technical-areas', [
            'label' => 'الأدلّة الجنائية',
            'sectorIds' => [Sector::where('code', 'DW')->value('id')],
        ])->assertCreated();

        $this->assertSame(1, TechnicalArea::where('label_ar', 'الأدلّة الجنائية')
            ->firstOrFail()->sectors()->count());
    }

    public function test_the_list_can_be_scoped_to_one_sector(): void
    {
        $dwOnly = $this->area('مجالٌ لديوان الوزارة', ['DW']);
        $prOnly = $this->area('مجالٌ للسجون', ['PR']);

        $this->actingAsRole('SCHEDULER');
        $dwId = Sector::where('code', 'DW')->value('id');

        $labels = collect($this->getJson("/api/technical-areas?sectorId={$dwId}")->assertOk()->json('areas'))
            ->pluck('label')->all();

        $this->assertContains($dwOnly->label_ar, $labels);
        $this->assertNotContains($prOnly->label_ar, $labels, 'مجال قطاعٍ آخر لا يظهر في نموذج هذا القطاع');
    }

    // ═══ وسم المستشار ═══

    public function test_a_consultant_is_tagged_with_technical_areas(): void
    {
        $ev = $this->person('EVALUATOR');
        $a = $this->area('القيادة');
        $b = $this->area('التحليل');

        $this->actingAsRole('ADMIN');
        $this->putJson("/api/users/{$ev->id}/technical-areas", ['areaIds' => [$a->id, $b->id]])
            ->assertOk();

        $this->assertSame(2, $ev->fresh()->technicalAreas()->count());

        // الإرسال استبدالٌ لا إضافة
        $this->putJson("/api/users/{$ev->id}/technical-areas", ['areaIds' => [$b->id]])->assertOk();
        $this->assertSame([$b->id], $ev->fresh()->technicalAreas()->pluck('technical_areas.id')->all());

        $this->putJson("/api/users/{$ev->id}/technical-areas", ['areaIds' => []])->assertOk();
        $this->assertSame(0, $ev->fresh()->technicalAreas()->count());
    }

    public function test_tagging_requires_a_managing_permission(): void
    {
        $ev = $this->person('EVALUATOR');
        $this->actingAsRole('RECEPTIONIST');

        $this->putJson("/api/users/{$ev->id}/technical-areas", ['areaIds' => []])
            ->assertStatus(403);
    }

    // ═══ المطابقة تقاطعاً ═══

    public function test_matching_is_the_intersection_of_both_taggings(): void
    {
        $shared = $this->area('القيادة');
        $his = $this->area('التحليل');
        $hers = $this->area('الأدلّة');

        [$c] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled']);
        $c->technicalAreas()->sync([$shared->id, $hers->id]);

        $match = $this->person('EVALUATOR', 'DW');
        $match->technicalAreas()->sync([$shared->id, $his->id]);

        $noMatch = $this->person('EVALUATOR', 'DW');
        $noMatch->technicalAreas()->sync([$his->id]);

        $this->actingAsRole('SCHEDULER');
        $rows = collect($this->getJson("/api/candidates/{$c->id}/assessors?activity=interview&seat=evaluator")
            ->assertOk()->json('assessors'))->keyBy('id');

        $this->assertSame(1, $rows[$match->id]['matchScore'], 'مجالٌ واحد مشترك');
        $this->assertSame([$shared->label_ar], $rows[$match->id]['matchedAreas']);

        // ترتيبٌ لا حجب: من تقاطعه صفر يبقى معروضاً قابلاً للاختيار
        $this->assertSame(0, $rows[$noMatch->id]['matchScore']);
        $this->assertArrayHasKey($noMatch->id, $rows->all(), 'غير المطابق يبقى في القائمة');
    }

    // ═══ رمز المستشار ═══

    public function test_a_consultant_code_is_one_or_two_capitals_and_unique(): void
    {
        $this->actingAsRole('ADMIN');
        $payload = $this->userPayload(['code' => 'K']);

        $this->postJson('/api/users', $payload)->assertCreated();
        $this->assertSame('K', User::where('username', $payload['username'])->value('code'));

        // مكرَّر
        $this->postJson('/api/users', $this->userPayload(['code' => 'K']))->assertStatus(422);
        // ثلاثة أحرف
        $this->postJson('/api/users', $this->userPayload(['code' => 'ABC']))->assertStatus(422);
        // صغيرة
        $this->postJson('/api/users', $this->userPayload(['code' => 'ab']))->assertStatus(422);
    }

    public function test_the_code_is_optional(): void
    {
        $this->actingAsRole('ADMIN');
        $payload = $this->userPayload();
        unset($payload['code']);

        $this->postJson('/api/users', $payload)->assertCreated();
        $this->assertNull(User::where('username', $payload['username'])->value('code'));
    }

    // ═══ هوية المستشار ═══

    public function test_the_identity_is_stored_encrypted_with_a_hash(): void
    {
        $this->actingAsRole('ADMIN');
        $nid = $this->validNationalId();
        $payload = $this->userPayload(['nationalId' => $nid]);

        $this->postJson('/api/users', $payload)->assertCreated();

        $row = DB::table('users')->where('username', $payload['username'])->first();
        $this->assertNotNull($row->national_id_enc);
        $this->assertNotSame($nid, $row->national_id_enc, 'الهوية لا تُخزَّن صريحة');
        $this->assertSame($nid, Crypt::decryptString($row->national_id_enc));
        $this->assertSame(hash('sha256', $nid), $row->national_id_hash, 'بصمةٌ للبحث بمطابقةٍ تامّة');
    }

    public function test_an_invalid_identity_is_rejected(): void
    {
        $this->actingAsRole('ADMIN');

        $this->postJson('/api/users', $this->userPayload(['nationalId' => '1234567890']))
            ->assertStatus(422);
    }

    public function test_the_identity_is_never_serialised(): void
    {
        $u = $this->person('EVALUATOR');
        $u->national_id = $this->validNationalId();
        $u->save();

        $array = $u->fresh()->toArray();
        $this->assertArrayNotHasKey('national_id_enc', $array);
        $this->assertArrayNotHasKey('national_id_hash', $array);
    }

    private function person(string $roleCode, string $sectorCode = 'DW'): User
    {
        $bound = in_array($roleCode, User::SECTOR_BOUND_ROLES, true);

        return User::create([
            'username' => 'u_'.substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'مستخدم '.$roleCode,
            'password' => 'Kafaat@2026',
            'role_id' => Role::where('code', $roleCode)->value('id'),
            'sector_id' => $bound ? Sector::where('code', $sectorCode)->value('id') : null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'u'.substr(md5(uniqid('', true)), 0, 8),
            'fullName' => 'مستشار اختبار',
            'roleId' => Role::where('code', 'EVALUATOR')->value('id'),
            'sectorId' => Sector::where('code', 'DW')->value('id'),
            'password' => 'Kafaat@2026!x',
            'userType' => 'external',
            'code' => 'ZQ',
        ], $overrides);
    }
}
