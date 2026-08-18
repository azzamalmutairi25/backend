<?php

use App\Data\MoiSectors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  زرع قطاعات وزارة الداخلية المعتمدة (١٩ قطاعاً) وإحالة الثمانية التجريبية.
//
//  كانت القاعدة تُبذر بثمانية قطاعات وهمية (الدفاع، الصحة، المالية…) لا وجود
//  لها في الوزارة — وهي التي كان الإنتاج يخرج بها. فكل مشارك يُضاف قبل ضبطها
//  يدوياً يُنسب إلى قطاعٍ لا يقابل جهته، ورمزه يصدر ببادئةٍ غريبة عنها.
//
//  ⚠ العمود full_name_ar جديد: الاسم الرسمي الكامل («المديرية العامة
//    للجوازات») يختلف عن المعروض («الجوازات»)، والمخاطبات تحتاج الأول.
//    nullable لأن قطاعاً يضيفه صاحب المنصّة من الشاشة قد لا يحمل اسماً رسمياً.
//
//  ⚠ updateOrInsert لا insert، وبمفتاح الرمز: الهجرة تُعاد على قاعدةٍ فيها
//    بعضُها فلا تنكسر بمفتاحٍ مكرّر. والتحديث يقتصر على الاسم الرسمي —
//    الاسم المعروض والتصنيف العسكري والبادئة تُكتب عند الإنشاء الأول فقط،
//    فإعادةُ الهجرة لا تدهس ما ضبطه صاحب المنصّة من شاشة الإعدادات.
//
//  ⚠ حذف الثمانية التجريبية هنا **مشروط بخلوّها**: قطاعٌ عليه مشارك أو
//    مستخدم أو صفّ توزيع يبقى كما هو مع تحذير صريح. حذفُه كان سيُيتّم صفوفه،
//    ونقلُه صامتاً كان سيغيّر بادئة رموز مشاركين صدرت وطُبعت على بطاقاتهم.
//    نقلُ المرتبط قرارٌ صريح له أمره:  php artisan kafaat:retire-demo-sectors
// ════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sectors', 'full_name_ar')) {
            Schema::table('sectors', function (Blueprint $table) {
                $table->string('full_name_ar', 200)->nullable()->after('name_ar');
            });
        }

        $now = now();
        foreach (MoiSectors::rows() as $row) {
            $exists = DB::table('sectors')->where('code', $row['code'])->exists();

            DB::table('sectors')->updateOrInsert(
                ['code' => $row['code']],
                $exists
                    // قائمٌ: الاسم الرسمي وحده يُحدَّث (مرجعيّ لا يُحرَّر من الشاشة)
                    ? ['full_name_ar' => $row['full_name_ar'], 'updated_at' => $now]
                    : $row + ['created_at' => $now, 'updated_at' => $now],
            );
        }

        // ── إحالة القطاعات التجريبية الثمانية ──
        $blocked = [];
        foreach (array_keys(MoiSectors::LEGACY_MAP) as $legacyCode) {
            $sector = DB::table('sectors')->where('code', $legacyCode)->first();
            if (!$sector) continue;

            if ($this->linkCount($sector->id) > 0) {
                $blocked[] = $legacyCode;
                continue;
            }

            DB::table('sectors')->where('id', $sector->id)->delete();
        }

        if ($blocked) {
            echo "⚠ لم تُحذف القطاعات التجريبية المرتبطة ببيانات: " . implode('، ', $blocked) . "\n"
               . "  لنقل بياناتها إلى القطاعات المعتمدة ثم حذفها:\n"
               . "      php artisan kafaat:retire-demo-sectors\n";
        }
    }

    public function down(): void
    {
        // يُحذف المزروع الخالي وحده — قطاعٌ ارتبط بمشاركين يبقى، والتراجع
        // عن هجرةٍ لا يجوز أن يُيتّم صفوفاً أُنشئت بعدها
        foreach (MoiSectors::codes() as $code) {
            $sector = DB::table('sectors')->where('code', $code)->first();
            if ($sector && $this->linkCount($sector->id) === 0) {
                DB::table('sectors')->where('id', $sector->id)->delete();
            }
        }

        if (Schema::hasColumn('sectors', 'full_name_ar')) {
            Schema::table('sectors', function (Blueprint $table) {
                $table->dropColumn('full_name_ar');
            });
        }
    }

    // عدد الصفوف المعلّقة بالقطاع في كل جدول يشير إليه
    private function linkCount(int $sectorId): int
    {
        $total = DB::table('candidates')->where('sector_id', $sectorId)->count()
            + DB::table('users')->where('sector_id', $sectorId)->count();

        if (Schema::hasTable('distribution_items')) {
            $total += DB::table('distribution_items')->where('sector_id', $sectorId)->count();
        }

        return $total;
    }
};
