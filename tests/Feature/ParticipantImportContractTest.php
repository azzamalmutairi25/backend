<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\CandidateCv;
use App\Models\TechnicalArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// عقد الاستيراد بعد نموذج المركز: الجنس والمجالات الفنية والسيرة اختيارية
// (راجع config/participants.php)، وما يردّه النموذج اليدوي يردّه الاستيراد
// بالسبب نفسه — والعكس. الردّ الآن على الصيغة الخاطئة لا على النقص.
//
// السبب أن الاستيراد كان باباً أوسع من الإضافة: يقبل ما ترفضه الشاشة، فيدخل
// من المسار الجماعي ما لا يدخل من الفردي. الرفضُ هنا يُقال بسببه لا برمز حالة:
// من يرفع عشرة آلاف صفّ يحتاج أن يعرف أي صفّ وأي حقل، لا أنّ «الاستيراد فشل».
class ParticipantImportContractTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function import(array $rows)
    {
        return $this->postJson('/api/candidates/import', ['rows' => $rows]);
    }

    public function test_a_complete_row_imports_with_cv_gender_and_areas(): void
    {
        $this->actingAsRole('SCHEDULER');
        $area = TechnicalArea::ordered()->first();

        $nid = $this->validNationalId();
        $this->import([$this->importRow([
            'nationalId' => $nid,
            'gender' => 'أنثى',
            'technicalAreas' => [$area->label_ar],
        ])])->assertOk()->assertJsonPath('imported', 1);

        $c = Candidate::where('national_id_hash', hash('sha256', $nid))->firstOrFail();

        $this->assertSame('female', $c->gender, 'الجنس يُقرأ بالعربية ويُخزَّن بالمفتاح');
        $this->assertSame([$area->id], $c->technicalAreas->pluck('id')->all());
        $this->assertNotNull($c->cv, 'السيرة تُحفظ مع المشارك في المعاملة نفسها');
        $this->assertSame('bachelor', $c->cv->data['qualifications'][0]['degree']);
    }

    // السيرة اختيارية في الاستيراد كما في الإضافة اليدوية — والصفّ يدخل
    // بلا صفِّ سيرةٍ فارغ يُلبِس الشاشات
    public function test_a_row_without_a_cv_is_imported_with_no_cv_row(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->import([$this->importRow(['cv' => []])])->assertOk();

        $this->assertSame(1, $res->json('imported'));
        $this->assertSame(0, CandidateCv::count(), 'لا يُنشأ صفُّ سيرةٍ فارغ');
    }

    public function test_a_cv_without_a_qualification_is_imported(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->import([$this->importRow([
            'cv' => $this->validCvDoc(['qualifications' => []]),
        ])])->assertOk();

        $this->assertSame(1, $res->json('imported'));
        $this->assertSame([], Candidate::first()->cv->data['qualifications']);
    }

    public function test_an_unknown_degree_names_the_accepted_values(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->import([$this->importRow([
            'cv' => $this->validCvDoc(['qualifications' => [[
                'degree' => 'مؤهل لا وجود له',
                'institution' => 'معهد الاختبار',
                'studyPlace' => 'السعودية',
            ]]]),
        ])])->assertOk();

        $this->assertSame(0, $res->json('imported'));
        $errors = implode(' ', $res->json('errors'));
        $this->assertStringContainsString('مؤهل لا وجود له', $errors, 'الرسالة تذكر القيمة المرفوضة');
        $this->assertStringContainsString('بكالوريوس', $errors, 'والرسالة تذكر المقبول ليُصحَّح الملفّ');
    }

    public function test_arabic_degrees_map_onto_the_closed_list(): void
    {
        $this->actingAsRole('SCHEDULER');

        $nid = $this->validNationalId();
        $this->import([$this->importRow([
            'nationalId' => $nid,
            'cv' => $this->validCvDoc(['qualifications' => [[
                'degree' => 'دكتوراة',   // إملاءٌ شائع بالتاء المربوطة
                'institution' => 'جامعة الاختبار',
                'studyPlace' => 'بريطانيا',
            ]]]),
        ])])->assertOk()->assertJsonPath('imported', 1);

        $c = Candidate::where('national_id_hash', hash('sha256', $nid))->firstOrFail();
        $this->assertSame('doctorate', $c->cv->data['qualifications'][0]['degree']);
    }

    public function test_an_unknown_technical_area_names_itself(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->import([$this->importRow([
            'technicalAreas' => ['مجال لا وجود له'],
        ])])->assertOk();

        $this->assertSame(0, $res->json('imported'));
        $this->assertStringContainsString('مجال لا وجود له', implode(' ', $res->json('errors')));
    }

    // غيابُ المجالات كلِّها لم يعد سبب ردّ (مجالٌ مكتوبٌ غير معروف ما زال كذلك،
    // ويثبته الاختبار أعلاه). والثمن مُعلَن: مشاركٌ بلا مجال لا يظهر في الترشيح.
    public function test_a_row_with_no_technical_area_is_imported(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->import([$this->importRow(['technicalAreas' => []])])->assertOk();

        $this->assertSame(1, $res->json('imported'));
        $this->assertSame([], Candidate::first()->technicalAreas->pluck('id')->all());
    }

    public function test_an_unknown_gender_names_the_accepted_values(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->import([$this->importRow(['gender' => 'غير محدّد'])])->assertOk();

        $this->assertSame(0, $res->json('imported'));
        $this->assertStringContainsString('ذكر أو أنثى', implode(' ', $res->json('errors')));
    }

    // النموذج يصف الوظيفة السابقة بمدّةٍ نصّية لا بسنتَي بداية ونهاية، ولا
    // يحمل سنة تخرّج ولا سنة دورة. الصفّ بهذا الشكل يمرّ كما هو.
    public function test_the_centres_own_shape_passes_without_years(): void
    {
        $this->actingAsRole('SCHEDULER');

        $nid = $this->validNationalId();
        $this->import([$this->importRow([
            'nationalId' => $nid,
            'cv' => $this->validCvDoc([
                'experiences' => [[
                    'position' => 'مدير إدارة',
                    'organization' => 'الإدارة العامة السابقة',
                    'section' => 'قسم المتابعة',
                    'years' => 'ثلاث سنوات',
                ]],
                'certifications' => [['name' => 'دورة القيادة التنفيذية']],
            ]),
        ])])->assertOk()->assertJsonPath('imported', 1);

        $cv = Candidate::where('national_id_hash', hash('sha256', $nid))->firstOrFail()->cv->data;

        $this->assertSame('قسم المتابعة', $cv['experiences'][0]['section']);
        $this->assertSame('ثلاث سنوات', $cv['experiences'][0]['years']);
        $this->assertNull($cv['experiences'][0]['fromYear'], 'الفراغ يبقى فراغاً لا صفراً');
        $this->assertNull($cv['certifications'][0]['year']);
        $this->assertNull($cv['qualifications'][0]['gradYear']);
    }

    // ٢٦ خانة دورة في النموذج، والسقف القديم عشرون كان يردّ من ملأه كاملاً
    public function test_twenty_six_courses_fit(): void
    {
        $this->actingAsRole('SCHEDULER');

        $courses = [];
        for ($i = 1; $i <= 26; $i++) {
            $courses[] = ['name' => "دورة رقم {$i}"];
        }

        $nid = $this->validNationalId();
        $this->import([$this->importRow([
            'nationalId' => $nid,
            'cv' => $this->validCvDoc(['certifications' => $courses]),
        ])])->assertOk()->assertJsonPath('imported', 1);

        $cv = Candidate::where('national_id_hash', hash('sha256', $nid))->firstOrFail()->cv->data;
        $this->assertCount(26, $cv['certifications']);
    }

    // صفٌّ يُردّ لا يترك مشاركاً بلا سيرة — المعاملة تُرجَع كلّها.
    // المُطلِق درجةٌ علمية غير معروفة: النقص لم يعد يردّ، والصيغة الخاطئة تردّ.
    public function test_a_rejected_row_leaves_nothing_behind(): void
    {
        $this->actingAsRole('SCHEDULER');
        $before = Candidate::count();

        $this->import([$this->importRow([
            'cv' => $this->validCvDoc(['qualifications' => [
                ['degree' => 'شهادة لا وجود لها', 'institution' => 'جامعة', 'studyPlace' => 'السعودية'],
            ]]),
        ])])->assertOk()->assertJsonPath('imported', 0);

        $this->assertSame($before, Candidate::count(), 'لا صفَّ ناقصاً يبقى بعد الرفض');
        $this->assertSame(0, CandidateCv::count());
    }

    // الأسباب تُجمع كلّها: من يصحّح ملفّه لا يعود إليه ثلاث مرّات
    public function test_all_reasons_for_a_row_are_reported_together(): void
    {
        $this->actingAsRole('SCHEDULER');

        $res = $this->import([$this->importRow([
            'gender' => 'غير محدّد',                       // قيمة مكتوبة غير مفهومة
            'technicalAreas' => ['مجال لا وجود له'],        // مجال غير معروف
            'mobile' => '966501234567',                    // صيغة جوال خاطئة
        ])])->assertOk();

        $reasons = $res->json('failures.0.reasons');
        $this->assertGreaterThanOrEqual(3, count($reasons), 'الأسباب الثلاثة تُقال معاً لا واحداً بعد واحد');
    }
}
