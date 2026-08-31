<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  أمران يتشاركان سبباً واحداً: مُعرِّفٌ يُكتب، ومُعرِّفاتٌ تُخزَّن عاريةً.
//
//  ١) الرقم العسكري/الوظيفي: يحمله نموذج الوزارة عموداً، ولا موضع له في
//     المنصّة — فيُقرأ ويُهمَل. وهو المُعرِّف الذي تعرف به الجهةُ منسوبيها في
//     مراسلاتها، فغيابُه يجعل مطابقة كشوفها بكشوفنا يدويّةً. يُخزَّن مشفّراً
//     كالهوية والجوال لا نصّاً: مُعرِّفٌ مباشر بمفهوم نظام حماية البيانات.
//
//  ٢) `import_batches.payload`: كان `json` صِرفاً يحمل الأسماء والهويات
//     والجوالات والسيرة كاملةً بلا تشفير — بينما جدول المشاركين يشفّر الثلاثة.
//     صفٌّ واحد في هذا العمود يُبطل تشفير الجدول كلّه. يصير `text` ويُشفَّر في
//     الموديل، وهو ما يفرض تحويل ما هو قائم.
// ════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            // بجوار الجوال: كلاهما مُعرِّفٌ مباشر مشفّر، وقُربهما يقول ذلك للقارئ
            $table->text('military_number_enc')->nullable()->after('mobile_enc');
        });

        // ── تحويل حمولات الدفعات القائمة ──
        // العمود يتغيّر نوعه، ومحتواه اليوم JSON عارٍ. الرفعات المنتهية تُفرَّغ
        // حمولتها أصلاً (ProcessCandidateImport)، فالمتبقّي قليلٌ وقيد التنفيذ.
        // لا نُعيد تشفيره صفّاً صفّاً هنا: رفعةٌ قيد المعالجة أثناء الترقية
        // حالةٌ نادرة، وإفراغُها يجعلها تُرفع ثانيةً — وذلك أسلم من حمولةٍ
        // نصفها مشفّر ونصفها عارٍ تُقرأ فتنفجر في منتصف الطابور.
        DB::table('import_batches')->whereNotNull('payload')->update(['payload' => null]);

        Schema::table('import_batches', function (Blueprint $table) {
            $table->text('payload')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('military_number_enc');
        });

        DB::table('import_batches')->whereNotNull('payload')->update(['payload' => null]);

        Schema::table('import_batches', function (Blueprint $table) {
            $table->json('payload')->nullable()->change();
        });
    }
};
