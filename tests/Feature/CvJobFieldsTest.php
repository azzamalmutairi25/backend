<?php

namespace Tests\Feature;

use App\Models\CandidateCv;
use App\Models\Schedule;
use App\Services\CvValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\EnablesCandidatePortal;
use Tests\TestCase;

// البيانات الوظيفية في نموذج المركز: تاريخا الميلاد والتعيين (ميلاديان)،
// والرتبة والإدارة والمنطقة — إلزامية، ومقر الدراسة في كل مؤهل.
class CvJobFieldsTest extends TestCase
{
    use RefreshDatabase;
    // البوّابة مُعطَّلة في التشغيل — تُشغَّل هنا لتبقى شيفرتها مُختبَرة
    use EnablesCandidatePortal;

    protected $seed = true;

    private function doc(array $over = []): array
    {
        return array_merge([
            'birthDate' => '1982-04-11',
            'appointmentDate' => '2006-09-01',
            'personnelCategory' => 'military',
            'personnelCategory' => 'military', 'rankLabel' => 'عميد',
            'department' => 'الإدارة العامة للعمليات',
            'region' => 'الرياض',
            'currentPosition' => 'مدير عام',
            'totalYearsExperience' => 19,
            'briefBio' => 'قيادي في العمليات',
            'qualifications' => [[
                'degree' => 'bachelor', 'major' => 'علوم أمنية',
                'institution' => 'جامعة نايف', 'studyPlace' => 'السعودية', 'gradYear' => 2004,
            ]],
            'experiences' => [[
                'position' => 'مدير إدارة', 'organization' => 'الإدارة العامة للمرور',
                'fromYear' => 2018, 'toYear' => null, 'current' => true, 'summary' => 'قيادة الفريق',
            ]],
            'certifications' => [['name' => 'القيادة التنفيذية', 'issuer' => 'المعهد', 'year' => 2022]],
        ], $over);
    }

    // ── الإلزام ──

    public function test_job_fields_are_required(): void
    {
        foreach (['birthDate', 'appointmentDate', 'rankLabel', 'department', 'region'] as $field) {
            $doc = $this->doc();
            unset($doc[$field]);
            try {
                (new CvValidator())->clean($doc);
                $this->fail("الحقل {$field} مرّ بلا قيمة وهو إلزامي");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    public function test_study_place_is_required_per_qualification(): void
    {
        $doc = $this->doc();
        unset($doc['qualifications'][0]['studyPlace']);

        $this->expectException(ValidationException::class);
        (new CvValidator())->clean($doc);
    }

    // ── التواريخ ميلادية بصيغة واحدة ──

    public function test_birth_date_must_be_iso_and_in_range(): void
    {
        foreach (['11-04-1982', '1982/04/11', '2020-01-01', '1930-01-01'] as $bad) {
            try {
                (new CvValidator())->clean($this->doc(['birthDate' => $bad]));
                $this->fail("تاريخ ميلاد غير مقبول مرّ: {$bad}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('birthDate', $e->errors());
            }
        }
    }

    public function test_appointment_date_cannot_be_in_the_future(): void
    {
        $this->expectException(ValidationException::class);
        (new CvValidator())->clean($this->doc([
            'appointmentDate' => now()->addYear()->toDateString(),
        ]));
    }

    // خلط الحقلين (تاريخ الميلاد في خانة التعيين) يُكشَف بفارق الثمانية عشر عاماً
    public function test_appointment_before_adulthood_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new CvValidator())->clean($this->doc([
            'birthDate' => '1990-01-01', 'appointmentDate' => '1995-01-01',
        ]));
    }

    // ── العمر مشتقّ لا مخزَّن ──

    public function test_age_is_derived_not_stored(): void
    {
        $clean = (new CvValidator())->clean($this->doc());
        $this->assertArrayNotHasKey('age', $clean);

        // عمر معلوم سلفاً: تاريخ ميلاد قبل ٣٠ سنة بيوم — العمر ٣٠ لا ٢٩
        $this->assertSame(30, CandidateCv::ageFrom(now()->subYears(30)->subDay()->toDateString()));
        // وقبل عيد الميلاد بيوم يكون ٢٩
        $this->assertSame(29, CandidateCv::ageFrom(now()->subYears(30)->addDay()->toDateString()));
        $this->assertNull(CandidateCv::ageFrom(null));
    }

    public function test_job_fields_survive_the_whitelist_rebuild(): void
    {
        $clean = (new CvValidator())->clean($this->doc());

        $this->assertSame('1982-04-11', $clean['birthDate']);
        $this->assertSame('2006-09-01', $clean['appointmentDate']);
        $this->assertSame('عميد', $clean['rankLabel']);
        $this->assertSame('الإدارة العامة للعمليات', $clean['department']);
        $this->assertSame('الرياض', $clean['region']);
        $this->assertSame('السعودية', $clean['qualifications'][0]['studyPlace']);
    }

    // وثيقة لا تحمل غير الإدارة ليست فارغة
    public function test_job_fields_count_toward_emptiness(): void
    {
        $empty = CandidateCv::emptyDoc();
        $this->assertTrue(CandidateCv::isEmptyDoc($empty));

        $empty['department'] = 'إدارة ما';
        $this->assertFalse(CandidateCv::isEmptyDoc($empty));
    }

    // ── إقرار الرتبة لا يستبدل الرتبة الرسمية ──

    public function test_declared_rank_does_not_overwrite_the_official_one(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW', 'rankLabel' => 'عقيد']);
        $token = Str::random(48);
        $a->update(['confirm_token' => $token]);
        $at = $this->postJson("/api/public/assessment/{$token}/verify", ['nationalId' => $c->national_id])
            ->json('accessToken');

        $this->postJson("/api/public/assessment/{$token}/cv", [
            'accessToken' => $at,
            'cv' => $this->doc(['personnelCategory' => 'military', 'rankLabel' => 'عميد']),
            'expectedVersion' => 0,
        ])->assertOk();

        // الرتبة في الملفّ لم تتغيّر — هي التي تقود تصنيف الفئة القيادية
        $this->assertSame('عقيد', $c->fresh()->rank_label);
        $this->assertSame('عميد', $c->fresh()->cv->data['rankLabel']);
    }

    public function test_rank_mismatch_is_flagged_to_staff(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW', 'rankLabel' => 'عقيد']);
        CandidateCv::create(['candidate_id' => $c->id, 'data' => $this->doc(['personnelCategory' => 'military', 'rankLabel' => 'عميد'])]);

        $this->actingAsRole('SCHEDULER');
        $cv = $this->getJson("/api/candidates/{$c->id}/cv")->assertOk()->json('cv');

        $this->assertTrue($cv['rankMismatch']);
        $this->assertSame('عقيد', $cv['officialRank']);
        $this->assertNotNull($cv['age']);
    }

    public function test_matching_rank_is_not_flagged(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW', 'rankLabel' => 'عميد']);
        CandidateCv::create(['candidate_id' => $c->id, 'data' => $this->doc(['personnelCategory' => 'military', 'rankLabel' => 'عميد'])]);

        $this->actingAsRole('SCHEDULER');
        $this->assertFalse($this->getJson("/api/candidates/{$c->id}/cv")->assertOk()->json('cv.rankMismatch'));
    }

    // ── المستند المطبوع ──

    public function test_cv_document_requires_the_cv_permission(): void
    {
        [$c] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        $this->actingAsRole('EVALUATOR');   // لا يملك candidate.cv_view

        $this->get("/api/candidates/{$c->id}/cv/document")->assertStatus(403);
    }

    public function test_cv_document_renders_the_form(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'sectorCode' => 'DW']);
        CandidateCv::create(['candidate_id' => $c->id, 'data' => $this->doc()]);
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->addDay()->toDateString(), 'schedule_time' => '12:30:00',
            'activity' => 'interview',
        ]);

        $this->actingAsRole('SCHEDULER');
        $html = $this->get("/api/candidates/{$c->id}/cv/document")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->getContent();

        foreach (['سيرة ذاتية', 'التعليم الأكاديمي', 'الخبرة العملية آخر عشر سنوات',
                  'الدورات التدريبية', 'الإدارة العامة للعمليات', 'الرياض', '12:30',
                  $c->participant_code] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        // I.A و I.A.A تُطبعان فارغتين للتعبئة اليدوية
        $this->assertStringContainsString('I.A', $html);
        $this->assertStringContainsString('I.A.A', $html);
    }

    // المستند وثيقة إدارية — ومع ذلك لا يحمل الاسم، كبقية مستندات النظام
    public function test_cv_document_never_carries_the_name(): void
    {
        [$c] = $this->makeCandidate([
            'status' => 'scheduled', 'sectorCode' => 'DW', 'fullName' => 'مرشح ذو اسم صريح',
        ]);
        CandidateCv::create(['candidate_id' => $c->id, 'data' => $this->doc()]);

        $this->actingAsRole('SCHEDULER');
        $html = $this->get("/api/candidates/{$c->id}/cv/document")->assertOk()->getContent();

        $this->assertStringNotContainsString('مرشح ذو اسم صريح', $html);
    }

    public function test_cv_document_is_out_of_scope_404(): void
    {
        [$c] = $this->makeCandidate([
            'status' => 'scheduled', 'sectorCode' => 'DW', 'classification' => 'top_secret',
        ]);
        $this->actingAsRole('SCHEDULER');   // بلا CANDIDATE_VIEW_CLASSIFIED

        $this->getJson("/api/candidates/{$c->id}/cv/document")->assertStatus(404);
    }
}
