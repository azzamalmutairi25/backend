<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════════════════
//  مخطّط بحيرة التقارير — يُنفَّذ على اتصال البحيرة وحده
//
//    php artisan migrate --database=pgsql_lake_ddl \
//                        --path=database/migrations/lake --force
//
//  الهجرة لا تُعيد كتابة الـDDL بلغة Laravel: تُنفّذ ملفّات deploy/lake/*.sql
//  نفسها. الملفّات هي مصدر الحقيقة الوحيد، فيُنفّذها مسؤولُ قواعد البيانات
//  بـpsql ويُنفّذها النشرُ الآليّ — ولا ينجرف أحدُهما عن الآخر. النسختان
//  المتوازيتان تتباعدان دائماً، والفرق يظهر على الإنتاج لا في المراجعة.
//
//  ما لا يُنفَّذ هنا: 00_bootstrap.sql — يُنشئ الأدوار والقاعدة نفسها
//  بصلاحية postgres، ولا يمكن لهجرةٍ أن تُنشئ القاعدةَ التي تتّصل بها.
//
//  كل ملفٍّ قابلٌ لإعادة التنفيذ (IF NOT EXISTS / CREATE OR REPLACE)،
//  فإعادةُ تشغيل الهجرة على قاعدةٍ قائمةٍ لا تُتلف شيئاً.
// ════════════════════════════════════════════════════════════════════════

return new class extends Migration
{
    private const FILES = [
        '01_raw.sql',
        '02_curated.sql',
        '03_meta.sql',
        '04_functions.sql',
        '05_contract_v1.sql',
        '06_grants.sql',
    ];

    public function up(): void
    {
        $conn = DB::connection($this->getConnection() ?: config('lake.ddl_connection'));

        foreach (self::FILES as $file) {
            $path = base_path('deploy/lake/' . $file);
            if (!is_file($path)) {
                throw new \RuntimeException("ملفّ مخطّط البحيرة مفقود: {$path}");
            }
            $conn->unprepared($this->stripPsqlMeta(file_get_contents($path)));
        }

        // كلُّ ملفٍّ يبدأ بـ SET search_path لمخطّطه، وهو إعدادُ جلسةٍ يبقى
        // بعد انتهاء الملفّ. بغير استعادته هنا يظلّ المسار على آخر ما ضبطه
        // ملفٌّ (curated)، فلا يجد Laravel جدولَ migrations غير المؤهَّل
        // ويسقط التسجيلُ بعد أن يكون المخطّط قد طُبِّق فعلاً — أي هجرةٌ
        // نجحت ولم تُسجَّل، فتُعاد في كل نشرٍ تالٍ.
        $conn->unprepared('SET search_path = ' . $conn->getConfig('search_path'));
    }

    /**
     * أوامر psql الخاصّة (\set، \connect، \gexec) ليست SQL ولا يفهمها PDO.
     * تُزال هنا لا من الملفّات: وجودُها فيها هو ما يجعلها صالحةً للتشغيل
     * اليدويّ بـpsql، وهو الغرض الذي وُجدت له.
     */
    private function stripPsqlMeta(string $sql): string
    {
        $out = [];
        foreach (explode("\n", $sql) as $line) {
            if (preg_match('/^\s*\\\\/', $line)) {
                continue;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    public function down(): void
    {
        $conn = DB::connection($this->getConnection() ?: config('lake.ddl_connection'));

        // العقد أوّلاً ثم المشتقّ ثم الدوالّ — أمّا raw فلا تُسقط هنا.
        // إسقاطُها في تراجعٍ آليّ يمحو سجلَّ الأحداث كلَّه، وهو الشيء
        // الوحيد في البحيرة الذي لا يُعاد بناؤه. تُسقَط بيدٍ واعية لا بأمر.
        $conn->unprepared('DROP SCHEMA IF EXISTS contract_v1 CASCADE;');
        $conn->unprepared('DROP SCHEMA IF EXISTS curated CASCADE;');
        $conn->unprepared('DROP SCHEMA IF EXISTS lake CASCADE;');
    }
};
