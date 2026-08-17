<?php

use App\Models\Rank;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  الفئة (مدني/عسكري/متعاقد) تنتقل من القطاع إلى المرشّح.
//
//  كانت مُعلَّقة على القطاع: `sectors.is_military` تحكم أن يُطلب «الرتبة» أم
//  «المرتبة» وتُحسب عليها الطبقة. وهو خطأ في محلّه لا في منطقه — القطاع جهةٌ
//  يعمل فيها مدنيّون وعسكريّون ومتعاقدون معاً، فوسمُه بواحدةٍ منها يُجبر
//  الجميع على قائمةٍ واحدة: مدنيٌّ في «الأمن العام» كان يُطلب منه رتبةٌ
//  عسكرية، وعسكريٌّ في «ديوان الوزارة» يُطلب منه مرتبةٌ مدنية.
//
//  الآن القطاع مسمّى فقط، والفئة صفةُ الشخص:
//    مدني   → قائمة المراتب المدنية، والطبقة تُحسب منها
//    عسكري  → قائمة الرتب العسكرية، والطبقة تُحسب منها
//    متعاقد → لا قائمة: مسمّى وظيفي حرّ، والطبقة تُختار صراحةً
//             (لا تُستنتج من نصٍّ حرّ، فاستنتاجُها منه تخمينٌ صامت)
//
//  ⚠ التعبئة الرجعية بمطابقة الرتبة المحفوظة على قائمة الرتب العسكرية:
//    من رتبتُه «عقيد» عسكريٌّ، ومن سواها مدنيّ. لا مصدر أدقّ — الفئة لم تكن
//    تُخزَّن أصلاً، وقطاعُ الحامل لا يدلّ عليها (كلّها مدنية اليوم). ومن أخطأت
//    فيه المطابقة يُصحَّح من شاشة المرشحين بلا أثر جانبي.
// ════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('candidates', 'personnel_category')) {
            Schema::table('candidates', function (Blueprint $table) {
                $table->string('personnel_category', 12)->nullable()->after('rank_label');
            });
        }

        // الرتب العسكرية المُدارة — بها تُستنتج فئة القائمين
        $military = Rank::where('category', 'military')->pluck('label')
            ->filter(fn ($l) => trim((string) $l) !== '')->values()->all();

        DB::table('candidates')->whereNull('personnel_category')
            ->orderBy('id')->chunkById(500, function ($rows) use ($military) {
                foreach ($rows as $c) {
                    $isMil = false;
                    foreach ($military as $label) {
                        if (mb_strpos((string) $c->rank_label, $label) !== false) { $isMil = true; break; }
                    }
                    DB::table('candidates')->where('id', $c->id)
                        ->update(['personnel_category' => $isMil ? 'military' : 'civilian']);
                }
            });

        // بعد التعبئة: العمود إلزاميّ — مرشّحٌ بلا فئة لا تُعرف قائمةُ رتبه
        DB::table('candidates')->whereNull('personnel_category')->update(['personnel_category' => 'civilian']);
        DB::statement("ALTER TABLE candidates ALTER COLUMN personnel_category SET DEFAULT 'civilian'");
        DB::statement('ALTER TABLE candidates ALTER COLUMN personnel_category SET NOT NULL');

        // القطاع صار مسمّى فقط — العَلَم يُحذف لا يُترك خاملاً:
        // عمودٌ باقٍ بمعنىً بطل يُقرأ يوماً فيُعيد العطل من حيث أُصلح
        if (Schema::hasColumn('sectors', 'is_military')) {
            Schema::table('sectors', function (Blueprint $table) {
                $table->dropColumn('is_military');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sectors', 'is_military')) {
            Schema::table('sectors', function (Blueprint $table) {
                $table->boolean('is_military')->default(false);
            });
        }

        if (Schema::hasColumn('candidates', 'personnel_category')) {
            Schema::table('candidates', function (Blueprint $table) {
                $table->dropColumn('personnel_category');
            });
        }
    }
};
