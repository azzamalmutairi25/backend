<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ثلاثة أشياء تجعل قواعد الاعتماد بيانات لا كوداً:
//   1) كل مساعد له مدير — «المدير يعتمد ما كتبه مساعده» يحتاج الرابط ليُفرض
//   2) أعلام على المرحلة: هل تمنع كاتب التقرير من اعتمادها؟ وهل تشترط أن يكون
//      الكاتب من فريق المعتمِد؟ إعدادان يُبدَّلان من الشاشة بدل أن يُحفرا
//   3) حالة «ملغي» — الإلغاء لا يمحو وثيقة قيادية، يوقفها ويُبقي أثرها
return new class extends Migration
{
    private const STATUSES = [
        'draft', 'pending_evaluator', 'pending_manager',
        'pending_dev_approval', 'pending_center', 'approved', 'returned', 'cancelled',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // مدير المستخدم — للمساعد: مدير إدارة التقييم الذي يعتمد تقاريره
            $table->foreignId('manager_id')->nullable()->after('sector_id')
                ->constrained('users')->nullOnDelete();
            $table->index('manager_id');
        });

        Schema::table('workflow_stages', function (Blueprint $table) {
            // كاتب التقرير لا يعتمد هذه المرحلة — «من يكتب لا يعتمد»
            $table->boolean('blocks_self_authored')->default(false)->after('is_active');
            // كاتب التقرير يجب أن يكون من فريق المعتمِد (manager_id)
            $table->boolean('requires_team_authorship')->default(false)->after('blocks_self_authored');
        });

        $this->setCheck(self::STATUSES);

        // مرحلة مدير التقييم: يعتمد ما كتبه مساعدوه، لا ما كتبه هو
        DB::table('workflow_stages')
            ->where('workflow', 'report')
            ->where('status_key', 'pending_manager')
            ->update(['blocks_self_authored' => true, 'requires_team_authorship' => true]);
    }

    public function down(): void
    {
        DB::table('final_reports')->where('status', 'cancelled')->update(['status' => 'returned']);
        $this->setCheck(array_values(array_diff(self::STATUSES, ['cancelled'])));

        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn(['blocks_self_authored', 'requires_team_authorship']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropIndex(['manager_id']);
            $table->dropColumn('manager_id');
        });
    }

    private function setCheck(array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));
        DB::statement('ALTER TABLE final_reports DROP CONSTRAINT IF EXISTS final_reports_status_check');
        DB::statement("ALTER TABLE final_reports ADD CONSTRAINT final_reports_status_check CHECK (status::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
