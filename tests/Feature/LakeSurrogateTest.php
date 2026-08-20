<?php

namespace Tests\Feature;

use App\Support\LakeRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════════════════
//  المعرّف البديل — ما يقوم عليه ادّعاء المجهوليّة كلُّه
//
//  البحيرة تحمل رقماً واحداً يخصّ الشخص: person_ref. عليه وحده يقوم
//  «كم دورةً خاض هذا الشخص» و«هل تحسّن بين دورتين»، وبدونه تصير البحيرة
//  عدّاداً لا سجلّاً. فله شرطان متضادّان ظاهرياً:
//    ثابتٌ عبر الزمن (وإلا انقطع الشخص عن نفسه)،
//    وغيرُ قابلٍ لإعادة الحساب من خارج خادم التطبيق (وإلا لم يكن بديلاً).
//
//  الفلفلُ هو ما يوفّق بينهما، ولذلك يُفشل غيابُه السكَّ صراحةً بدل أن
//  يُنتج معرّفاً يبدو مجهولاً وهو يُعاد حسابُه بسطرٍ واحد. الفشل الصامت
//  هنا أسوأ من التعطّل: لا يُرى إلا بعد أن تُشحن آلافُ الصفوف.
// ════════════════════════════════════════════════════════════════════════
class LakeSurrogateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const PEPPER = 'a-pepper-of-thirty-two-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['lake.pepper' => self::PEPPER]);
    }

    public function test_person_ref_is_stable_across_calls(): void
    {
        $first = LakeRef::person(4242);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, LakeRef::person(4242));
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    public function test_person_ref_differs_per_candidate(): void
    {
        $this->assertNotSame(LakeRef::person(1), LakeRef::person(2));
        // ومعرّفان متجاوران لا يُنتجان قيمتين متجاورتين — لو أنتجا لَاستُدلّ
        // على ترتيب الالتحاق من ترتيب المعرّفات.
        $this->assertNotSame(substr(LakeRef::person(1), 0, 8), substr(LakeRef::person(2), 0, 8));
    }

    public function test_person_ref_changes_when_the_pepper_changes(): void
    {
        $before = LakeRef::person(4242);

        config(['lake.pepper' => 'a-different-pepper-of-32-chars!!']);
        $after = LakeRef::person(4242);

        $this->assertNotSame($before, $after,
            'الفلفل لا يدخل في الحساب — أي أن المعرّف يُعاد حسابُه بلا سرّ');

        // وهذا هو ثمنُ التغيير: الشخص ينقطع عمّا سبق في البحيرة.
        // يُغيَّر بقرارٍ موثّق، لا بالخطأ ولا عند تدوير الأسرار الروتيني.
        config(['lake.pepper' => self::PEPPER]);
        $this->assertSame($before, LakeRef::person(4242));
    }

    // ليس تجزئةً عادية: SHA-256 على المعرّف نفسه يُعيد حسابَه أيُّ أحد
    // يملك جدولاً من عشرة ملايين معرّف.
    public function test_person_ref_is_not_a_plain_hash_of_the_candidate_id(): void
    {
        $this->assertNotSame(hash('sha256', '4242'), LakeRef::person(4242));
        $this->assertNotSame(hash('sha256', 'candidate:4242'), LakeRef::person(4242));
    }

    public function test_person_ref_throws_when_the_pepper_is_unset(): void
    {
        config(['lake.pepper' => null]);

        $this->expectException(\RuntimeException::class);
        LakeRef::person(4242);
    }

    public function test_person_ref_throws_when_the_pepper_is_too_short(): void
    {
        config(['lake.pepper' => str_repeat('x', 15)]); // ١٥ محرفاً: أقلّ من الحدّ

        $this->expectException(\RuntimeException::class);
        LakeRef::person(4242);
    }

    public function test_person_ref_accepts_the_minimum_length(): void
    {
        // الحدّ ١٦ محرفاً — يُفحص الطرفان حتى لا ينزلق الشرط إلى < أو <=
        // بلا أن يلحظه أحد.
        config(['lake.pepper' => str_repeat('x', 16)]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', LakeRef::person(4242));
    }

    // معرّف الفاعل يمرّ بالحارس نفسه — ولا يُسكّ لمن لا وجود له
    public function test_actor_ref_shares_the_guard_and_passes_null_through(): void
    {
        $this->assertNull(LakeRef::actor(null));
        $this->assertNotSame(LakeRef::person(7), LakeRef::actor(7));

        config(['lake.pepper' => null]);
        $this->expectException(\RuntimeException::class);
        LakeRef::actor(7);
    }
}
