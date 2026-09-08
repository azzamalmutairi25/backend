<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Security\Permissions;
use App\Services\NameIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// الخدمة السادسة: البحث ثلاث طرق بثلاث صلاحيات — والاسم يبقى مشفَّراً.
class ThreeWaySearchTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function found(string $term): array
    {
        return collect($this->getJson('/api/candidates?search='.urlencode($term))->assertOk()->json('candidates'))
            ->pluck('id')->all();
    }

    // ═══ البحث بالرمز ═══

    public function test_code_search_matches_by_prefix_not_by_substring(): void
    {
        [$a] = $this->makeCandidate(['code' => 'DW0007Aug26']);
        [$b] = $this->makeCandidate(['code' => 'PR0007Sep26']);

        $this->actingAsRole('SCHEDULER');

        $this->assertSame([$a->id], $this->found('DW'), 'البادئة تجد قطاعها');
        $this->assertSame([$a->id], $this->found('DW0007'), 'وتضيّق');

        // بالبداية لا بالاحتواء: «0007» في وسط الرمزين ولا يُطابق أيّاً منهما
        $this->assertSame([], $this->found('0007'));
    }

    public function test_a_leading_star_asks_for_a_substring(): void
    {
        [$a] = $this->makeCandidate(['code' => 'DW0007Aug26']);
        $this->makeCandidate(['code' => 'PR0011Sep26']);

        $this->actingAsRole('SCHEDULER');
        $this->assertSame([$a->id], $this->found('*Aug26'));
    }

    // ═══ البحث بالهوية ═══

    public function test_a_full_national_id_finds_its_owner(): void
    {
        $nid = $this->validNationalId();
        [$a] = $this->makeCandidate(['nationalId' => $nid, 'code' => 'DW0001Aug26']);
        $this->makeCandidate(['code' => 'DW0002Aug26']);

        $this->actingAsRole('SCHEDULER');
        $this->assertSame([$a->id], $this->found($nid));
    }

    // من لا يملكها لا يُفصح له بأنّ الرقم هويةٌ صالحة — يُعامَل رمزاً فلا يجد
    public function test_without_the_permission_an_id_finds_nothing_and_says_nothing(): void
    {
        $nid = $this->validNationalId();
        $this->makeCandidate(['nationalId' => $nid, 'code' => 'DW0001Aug26']);

        $this->actingAsRole('EVALUATOR', 'DW');
        $this->getJson('/api/candidates?search='.$nid)->assertOk()->assertJsonCount(0, 'candidates');
    }

    public function test_searching_by_id_is_audited(): void
    {
        $nid = $this->validNationalId();
        $this->makeCandidate(['nationalId' => $nid]);

        $this->actingAsRole('SCHEDULER');
        $this->found($nid);

        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'SEARCH_BY_NATIONAL_ID')->exists(),
            'البحث بالهوية يبلغ الشخص بعينه — فيُدوَّن'
        );
    }

    // ═══ البحث بالاسم ═══

    public function test_a_partial_word_finds_the_name(): void
    {
        [$a] = $this->makeCandidate(['fullName' => 'محمد بن علي الفلاني', 'code' => 'DW0001Aug26']);
        $this->makeCandidate(['fullName' => 'سعد بن ناصر القحطاني', 'code' => 'DW0002Aug26']);

        $this->actingAsRole('SCHEDULER');

        $this->assertSame([$a->id], $this->found('محم'), 'جزءٌ من كلمة');
        $this->assertSame([$a->id], $this->found('فلان'), 'جزءٌ من كلمةٍ أخرى');
        $this->assertSame([$a->id], $this->found('محمد'), 'الكلمة كاملةً');
    }

    // التطبيع: الهمزات والتاء المربوطة و«ال» التعريف لا تفرّق
    public function test_normalisation_folds_hamza_and_the_definite_article(): void
    {
        [$a] = $this->makeCandidate(['fullName' => 'أحمد بن إبراهيم الغامدي', 'code' => 'DW0001Aug26']);

        $this->actingAsRole('SCHEDULER');

        $this->assertSame([$a->id], $this->found('احمد'), 'الهمزة لا تفرّق');
        $this->assertSame([$a->id], $this->found('غامدي'), '«ال» التعريف تُزال');
    }

    public function test_fewer_than_three_letters_finds_nothing(): void
    {
        $this->makeCandidate(['fullName' => 'محمد بن علي الفلاني']);

        $this->actingAsRole('SCHEDULER');
        $this->assertSame([], $this->found('مح'), 'حرفان يطابقان نصف القاعدة');
    }

    public function test_without_the_permission_a_name_finds_nothing(): void
    {
        $this->makeCandidate(['fullName' => 'محمد بن علي الفلاني']);

        $this->actingAsRole('EVALUATOR', 'DW');
        $this->assertSame([], $this->found('محمد'));
    }

    // ═══ الفهرس نفسه ═══

    public function test_the_name_is_never_stored_in_the_index(): void
    {
        [$a] = $this->makeCandidate(['fullName' => 'محمد بن علي الفلاني']);

        $grams = DB::table('candidate_name_grams')->where('candidate_id', $a->id)->pluck('gram');

        $this->assertGreaterThan(0, $grams->count(), 'الفهرس يُبنى عند الحفظ');
        foreach ($grams as $g) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $g, 'بصمة لا نصّ');
            $this->assertStringNotContainsString('محمد', $g);
        }
    }

    public function test_renaming_rebuilds_the_index(): void
    {
        [$a] = $this->makeCandidate(['fullName' => 'محمد بن علي الفلاني', 'code' => 'DW0001Aug26']);

        $this->actingAsRole('SCHEDULER');
        $this->assertSame([$a->id], $this->found('فلان'));

        $a->full_name = 'سعد بن ناصر القحطاني';
        $a->save();

        // الاسم القديم لم يعد يجده، والجديد يجده — وإلا وُجد باسمٍ لا يحمله
        $this->assertSame([], $this->found('فلان'));
        $this->assertSame([$a->id], $this->found('قحطان'));
    }

    // المقاطع تُقطَّع داخل الكلمة لا عبر الفراغ: مقطعٌ يعبر كلمتين يربط ما لا
    // علاقة بينهما فيُطابق أسماءً لا تشبهه
    public function test_grams_do_not_cross_word_boundaries(): void
    {
        $grams = NameIndex::grams('فلان بن');

        $this->assertContains(hash('sha256', 'فلا'), $grams);
        $this->assertContains(hash('sha256', 'لان'), $grams);
        $this->assertNotContains(hash('sha256', 'نبن'), $grams, 'لا مقطع يعبر الفراغ');
    }

    // ═══ الصلاحيتان ═══

    public function test_both_search_permissions_are_non_delegable(): void
    {
        $this->assertContains(Permissions::CANDIDATE_SEARCH_BY_ID, Permissions::NON_DELEGABLE);
        $this->assertContains(Permissions::CANDIDATE_SEARCH_BY_NAME, Permissions::NON_DELEGABLE);
    }

    public function test_the_index_follows_the_candidate_on_delete(): void
    {
        [$a] = $this->makeCandidate(['fullName' => 'محمد بن علي الفلاني', 'status' => 'draft']);
        $this->assertGreaterThan(0, DB::table('candidate_name_grams')->where('candidate_id', $a->id)->count());

        Candidate::whereKey($a->id)->delete();

        $this->assertSame(0, DB::table('candidate_name_grams')->where('candidate_id', $a->id)->count());
    }
}
