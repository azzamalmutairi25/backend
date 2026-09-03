<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  «تنفيذي» ← «طلب خاص»
//
//  نوع التقييم صار اثنين: شامل، وطلب خاص. و«تنفيذي» لم يكن يصف نوع
//  التقييم بل صفةَ صاحبه، فحلّ محلَّه ما يصف الحالة فعلاً: طلبٌ خاص خارج
//  المسار الشامل.
//
//  الترتيب: يُسقَط القيد، ثمّ تُرحَّل الصفوف، ثمّ يُبنى القيد الجديد. والقيد
//  أوّلاً لا آخراً لأن القيد القائم يحرس العمود أثناء التحديث نفسه: كتابة
//  «طلب خاص» وهو لم يُذكر بعدُ في CHECK تُرفض في الحال — وهو ما وقع فعلاً
//  عند أوّل تشغيل على قاعدةٍ فيها ٢٢ صفّاً تنفيذياً.
//
//  والعمودان يُعالَجان معاً: `assessment_type` مكرَّر في `candidates`
//  (سمة الدورة الحالية) وفي `assessments` (سجلّ كل دورة). تركُ أحدهما
//  يترك قيداً يرفض ما يكتبه المتحكّم في الآخر.
// ════════════════════════════════════════════════════════════
return new class extends Migration
{
    private const TABLES = ['candidates', 'assessments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'assessment_type')) {
                continue;
            }

            $this->dropCheck($table);

            DB::table($table)->where('assessment_type', 'executive')
                ->update(['assessment_type' => 'special_request']);

            $this->setCheck($table, ['comprehensive', 'special_request']);
        }
    }

    // الرجوع يعيد التسمية القديمة ويردّ القيد كما كان — ولا يستطيع أن يفرّق
    // بين «تنفيذيٍّ رُحِّل» و«طلبٍ خاص أُنشئ بعد الهجرة»، فكلاهما يعود تنفيذياً.
    // هذا مذكور صراحةً لأنه فقدُ معلومةٍ لا يُكتشف إلا بعد الرجوع.
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'assessment_type')) {
                continue;
            }

            $this->dropCheck($table);

            DB::table($table)->where('assessment_type', 'special_request')
                ->update(['assessment_type' => 'executive']);

            $this->setCheck($table, ['comprehensive', 'executive']);
        }
    }

    // enum في لارافل على بوستجرس نصٌّ بقيد CHECK — يُبدَّل بإسقاطه وإعادة
    // بنائه، لا بـchange() التي تحتاج doctrine/dbal ولا تمسّ القيد أصلاً
    private function dropCheck(string $table): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_assessment_type_check");
    }

    private function setCheck(string $table, array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_assessment_type_check CHECK (assessment_type::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
