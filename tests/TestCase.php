<?php

namespace Tests;

use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    // مستخدم بدور محدّد + مصادقة عبر Sanctum (يرجع المستخدم)
    //
    // الأدوار المحصورة بقطاع تُنشأ بقطاع — لا يوجد مقيّم بلا قطاع في النظام،
    // فمصنعٌ ينشئه لا يمثّل الواقع. الافتراضي ED ليطابق makeCandidate الافتراضي،
    // فيبقى الاختبار الذي لا يعني القطاع شيئاً بالنسبة له عاملاً كما كان.
    // مرّر sectorCode لاختبار حدود القطاع صراحةً.
    protected function actingAsRole(string $roleCode, ?string $sectorCode = null): User
    {
        $role = Role::where('code', $roleCode)->firstOrFail();
        $bound = in_array($roleCode, User::SECTOR_BOUND_ROLES, true);

        $user = User::create([
            'username' => 'u_' . strtolower($roleCode) . '_' . substr(md5(uniqid('', true)), 0, 6),
            'full_name' => "مستخدم {$roleCode}",
            'email' => strtolower($roleCode) . '.' . substr(md5(uniqid('', true)), 0, 6) . '@kafaat.local',
            'password' => 'Kafaat@2026',
            'role_id' => $role->id,
            'sector_id' => $bound ? Sector::where('code', $sectorCode ?? 'ED')->value('id') : null,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    // مرشح (+ دورة تقييم) بحالة/تصنيف محدّدين — يرجع [candidate, assessment]
    protected function makeCandidate(array $attrs = []): array
    {
        $sector = Sector::where('code', $attrs['sectorCode'] ?? 'ED')->firstOrFail();
        $status = $attrs['status'] ?? 'draft';
        $code = $attrs['code'] ?? ('T' . random_int(1000, 999999));

        $c = new Candidate();
        $c->national_id = $attrs['nationalId'] ?? $this->validNationalId();
        $c->full_name = $attrs['fullName'] ?? 'مرشح اختبار';
        $c->mobile = $attrs['mobile'] ?? '0501112223';
        $c->sector_id = $sector->id;
        $c->rank_label = $attrs['rankLabel'] ?? 'مدير عام';
        $c->tier = $attrs['tier'] ?? 'upper';
        $c->status = $status;
        $c->classification = $attrs['classification'] ?? 'normal';
        $c->participant_code = $code;
        $c->save();

        $a = Assessment::create([
            'candidate_id' => $c->id,
            'participant_code' => $code,
            'assessment_type' => 'comprehensive',
            'status' => $attrs['assessmentStatus'] ?? $status,
            'created_by' => null,
        ]);

        return [$c, $a];
    }

    // رقم هوية سعودي صالح (Luhn، يبدأ بـ1) فريد لكل استدعاء
    protected function validNationalId(): string
    {
        do {
            $body = '1' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $d = (int) $body[$i];
                if ($i % 2 === 0) { $dd = $d * 2; $sum += $dd > 9 ? $dd - 9 : $dd; }
                else { $sum += $d; }
            }
            $check = (10 - ($sum % 10)) % 10;
            $id = $body . $check;
        } while (Candidate::where('national_id_hash', hash('sha256', $id))->exists());
        return $id;
    }
}
