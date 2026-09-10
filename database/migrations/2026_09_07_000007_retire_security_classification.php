<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// إخراج التصنيف الأمني من التشغيل — الخطوة الأولى من اثنتين.
//
// `classification` ليس حقلاً في نموذج، بل **بوّابة نطاق** تُفرض في التقييم
// والتقارير والجدولة والاستقبال والحضور والتحليلات وبحيرة البيانات: مذكورةٌ
// في مئةٍ واثني عشر موضعاً عبر تسعةٍ وعشرين ملفّاً. ونزعُها دفعةً واحدة يمسّ
// كل مسارٍ يقرأ بيانات مشارك.
//
// فالإخراج على خطوتين، وهذه أولاهما:
//   • كل صفٍّ يصير 'normal' — وهو الترحيل الذي لا غنى عنه: لو بقي صفٌّ
//     مصنَّفاً بعد نزع الحقل من الشاشات، لاختفى عن كل من لا يملك التصريح
//     ولا سبيل له إلى إعادته.
//   • والحقل يُنزع من النماذج والفلاتر والإحصاءات ومسار إعادة التصنيف —
//     في التغيير نفسه، لا في هجرة.
//
// والحراسات تبقى في مكانها بلا أثر: `allowedClassifications` تُرجع
// ['normal'] لغير المصرَّح، وكل الصفوف 'normal'، فلا تحجب شيئاً. تركُها
// أسلم من نزعها الآن — هي نفسها التي تردّ 404 بدل 403 كيلا يكشف المعرّف
// وجودَ صفٍّ محجوب، وحدُّ القطاع يتّكئ على النمط نفسه.
//
// والخطوة الثانية — إسقاط العمود ونزع الحراسات — تأتي وحدها بعد أن يستقرّ
// هذا، فلا يجتمع ترحيلُ بياناتٍ ونزعُ حارسٍ في تغييرٍ واحد.
return new class extends Migration
{
    public function up(): void
    {
        $moved = DB::table('candidates')->where('classification', '!=', 'normal')->update([
            'classification' => 'normal',
        ]);

        // ونزعُ الصلاحية من كل دورٍ يحملها: المنحُ محفوظٌ في القاعدة لا في
        // الشيفرة، فحذفُها من قائمة الأدوار الافتراضية لا يمسّ ما مُنح فعلاً.
        $revoked = DB::table('role_permissions')
            ->where('permission', 'candidate.view_classified')->delete();
        DB::table('user_permission_overrides')
            ->where('permission', 'candidate.view_classified')->delete();

        if ($moved > 0 || $revoked > 0) {
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'RETIRE_CLASSIFICATION',
                'entity_type' => 'candidate',
                'entity_id' => '0',
                'details' => json_encode(['moved' => $moved, 'revoked' => $revoked], JSON_UNESCAPED_UNICODE),
                'ip_address' => null,
                'created_at' => now(),
            ]);
        }
    }

    // لا رجعة: القيم الأصلية لم تُحفَظ، وإعادةُ الكلّ إلى 'secret' أسوأ من
    // بقائها 'normal'. والتراجع يترك الحقل كما هو ويكتفي بردّ الشاشات.
    public function down(): void {}
};
