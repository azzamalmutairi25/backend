<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// مرحلتا اعتماد جديدتان قبل تطوير الكفاءات:
//   مسودة → المقيّم → مدير التقييم → تطوير الكفاءات → معتمد
//
// pending_dev_approval يبقى باسمه: هو نفسه المرحلة الأخيرة، واسمه دقيق،
// والتقارير القائمة عليه تبقى صالحة بلا ترحيل بيانات.
return new class extends Migration
{
    private const OLD = ['draft', 'pending_dev_approval', 'approved', 'returned'];
    private const NEW = ['draft', 'pending_evaluator', 'pending_manager', 'pending_dev_approval', 'approved', 'returned'];

    public function up(): void
    {
        $this->setCheck(self::NEW);
    }

    public function down(): void
    {
        // المرحلتان الجديدتان لا تمثيل لهما في المخطّط القديم — أرجِعهما لأقرب حالة
        // قائمة (بانتظار الاعتماد) بدل ترك صفوف تخالف القيد فيفشل التراجع.
        DB::table('final_reports')
            ->whereIn('status', ['pending_evaluator', 'pending_manager'])
            ->update(['status' => 'pending_dev_approval']);

        $this->setCheck(self::OLD);
    }

    private function setCheck(array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));
        DB::statement('ALTER TABLE final_reports DROP CONSTRAINT IF EXISTS final_reports_status_check');
        DB::statement("ALTER TABLE final_reports ADD CONSTRAINT final_reports_status_check CHECK (status::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
