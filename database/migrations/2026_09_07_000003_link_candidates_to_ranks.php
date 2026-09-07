<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ربط المشارك برتبةٍ مُدارة بدل نصٍّ حرّ.
//
// `candidates.rank_label` نصٌّ حرّ، وفي القاعدة تجتمع عليه اليوم ثلاثُ صيغٍ
// للشيء نفسه: «م-14» في سجلّ المشاركين، و«الرابعة عشرة» في جدول الرتب،
// و«المرتبة 14» في مصدر المركز. فلا يطابق `Rank::tierFor` إلّا مشاركَين من
// مئةٍ وستّةٍ وثلاثين، والباقي يسقط على regex في `Candidate::classifyTier`.
//
// المرضُ معروفُ العاقبة: المصدر نفسه — وهو حقلٌ حرٌّ مثله — أنتج مئةً وستّةً
// وسبعين صيغةً لأربعٍ وعشرين رتبة، فتعذّر حسابُ التوصية في سبعٍ وعشرين بالمئة
// من التقارير. ومصفوفةُ الرتبة × الكفاءة لا تقوم على مطابقةٍ نصّية.
//
// فـ`rank_id` مفتاحٌ صريح، و`rank_label` يبقى كما هو: هو ما كُتب وقت الرصد،
// ولا يُعاد كتابةُ التاريخ لأنّ المرجع تغيّر.
return new class extends Migration
{
    // الرتب الناقصة عن النموذج المعتمد.
    // «فريق» أعلى الرتب العسكرية. والمتعاقدون سُلّمٌ موازٍ صرّح
    // `Candidate::classifyTier` بأنّ طبقتهم «تُختار صراحةً لا تُستنتج»، فهي
    // مكتوبةٌ هنا لا محسوبة: متعاقد ٦ و٧ يقابلان عميداً ولواءً في المصفوفة.
    private const MISSING = [
        ['فريق', 'military', 'upper', 90],
        ['متعاقد 1', 'contractor', 'middle', 10],
        ['متعاقد 2', 'contractor', 'middle', 20],
        ['متعاقد 3', 'contractor', 'middle', 30],
        ['متعاقد 4', 'contractor', 'middle', 40],
        ['متعاقد 5', 'contractor', 'middle', 50],
        ['متعاقد 6', 'contractor', 'upper', 60],
        ['متعاقد 7', 'contractor', 'upper', 70],
    ];

    // مرادفاتٌ لُوحظت فعلاً في البيانات: «م-14» في سجلّنا، و«المرتبة 14»
    // و«الثامنه» و«الدرجة 8» في مصدر المركز. تُطبَّق مرّةً عند الترحيل ثم
    // يحمل `rank_id` الربط، فلا تتراكم قائمةٌ تُصان إلى الأبد.
    private const GRADES = [
        6 => 'السادسة', 7 => 'السابعة', 8 => 'الثامنة', 9 => 'التاسعة', 10 => 'العاشرة',
        11 => 'الحادية عشرة', 12 => 'الثانية عشرة', 13 => 'الثالثة عشرة',
        14 => 'الرابعة عشرة', 15 => 'الخامسة عشرة',
    ];

    // لواحقُ سلاحٍ أو تأهيل، لا رتبٌ: «عقيد بحري» عقيدٌ في المصفوفة.
    private const SUFFIXES = ['بحري', 'ركن', 'طيار', 'مهندس', 'مُهندس'];

    public function up(): void
    {
        $now = now();

        // القيد يحصر الفئة في عسكريّ ومدنيّ، والمتعاقدون فئةٌ ثالثة قائمة
        // أصلاً في `candidates.personnel_category`. يُوسَّع لا يُلغى.
        DB::statement('ALTER TABLE ranks DROP CONSTRAINT IF EXISTS ranks_category_check');
        DB::statement("ALTER TABLE ranks ADD CONSTRAINT ranks_category_check
            CHECK (category IN ('military', 'civilian', 'contractor'))");

        foreach (self::MISSING as [$label, $category, $tier, $sort]) {
            DB::table('ranks')->updateOrInsert(
                ['category' => $category, 'label' => $label],
                ['tier' => $tier, 'sort_order' => $sort, 'is_active' => true,
                    'updated_at' => $now, 'created_at' => $now]
            );
        }

        Schema::table('candidates', function (Blueprint $table) {
            // nullable عمداً: «مستشار تقنية المعلومات» مسمّى وظيفة لا رتبة،
            // وحقلٌ كهذا يُترك فارغاً فيُرى، لا يُخمَّن فيُصدَّق.
            $table->foreignId('rank_id')->nullable()->after('rank_label')
                ->constrained('ranks')->nullOnDelete();
        });

        $ranks = DB::table('ranks')->where('is_active', true)->get();

        foreach (DB::table('candidates')->select('id', 'rank_label', 'personnel_category')->get() as $cand) {
            $id = $this->resolve($cand->rank_label, $cand->personnel_category, $ranks);
            if ($id !== null) {
                DB::table('candidates')->where('id', $cand->id)->update(['rank_id' => $id]);
            }
        }
    }

    // الترتيب مقصود: المطابقة التامّة أوّلاً، ثم الدرجة الرقمية، ثم نزع
    // اللاحقة. الأخيرُ آخرُها لأنّه الأكثر تساهلاً.
    private function resolve(string $label, string $category, $ranks): ?int
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label));
        if ($label === '') {
            return null;
        }

        $in = $ranks->where('category', $category);
        $pool = $in->isEmpty() ? $ranks : $in;

        if ($hit = $pool->firstWhere('label', $label)) {
            return $hit->id;
        }

        // «م-14» · «م14» · «المرتبة 14» · «الدرجة 14» · «14»
        if (preg_match('/(?:^|\D)(\d{1,2})(?:\D|$)/u', $label, $m)) {
            $g = (int) $m[1];
            if (isset(self::GRADES[$g]) && ($hit = $pool->firstWhere('label', self::GRADES[$g]))) {
                return $hit->id;
            }
        }

        // «عقيد بحري ركن» ← «عقيد»
        $bare = trim(str_replace(self::SUFFIXES, '', $label));
        if ($bare !== '' && $bare !== $label && ($hit = $pool->firstWhere('label', $bare))) {
            return $hit->id;
        }

        // أطولُ رتبةٍ يحويها النصّ: يمنع مطابقة «ملازم» داخل «ملازم أول»
        return $pool->filter(fn ($r) => $r->label !== '' && mb_strpos($label, $r->label) !== false)
            ->sortByDesc(fn ($r) => mb_strlen($r->label))
            ->first()?->id;
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rank_id');
        });

        DB::table('ranks')->where('category', 'contractor')->delete();
        DB::table('ranks')->where('category', 'military')->where('label', 'فريق')->delete();

        DB::statement('ALTER TABLE ranks DROP CONSTRAINT IF EXISTS ranks_category_check');
        DB::statement("ALTER TABLE ranks ADD CONSTRAINT ranks_category_check
            CHECK (category IN ('military', 'civilian'))");
    }
};
