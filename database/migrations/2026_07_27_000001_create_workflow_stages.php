<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// سلسلة الاعتماد تصير بيانات بدل ثوابت في الكود، لتُعدَّل من الشاشة.
//
// status_key محصور بمفردات ثابتة يفرضها قيد final_reports_status_check —
// المشرف يعيد الترتيب ويُفعّل/يُعطّل، ولا يخترع حالات. حالةٌ حرّة تعني صفوفاً
// تخالف القيد، أو إسقاط القيد وفقدان الحارس الوحيد على الحالة.
return new class extends Migration
{
    private const STATUSES = [
        'draft', 'pending_evaluator', 'pending_manager',
        'pending_dev_approval', 'pending_center', 'approved', 'returned',
    ];

    public function up(): void
    {
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->string('workflow', 30);          // 'report' — يتّسع لسلاسل أخرى لاحقاً
            $table->unsignedSmallInteger('position'); // ترتيب المرحلة في السلسلة
            $table->string('status_key', 40);        // الحالة التي تمثّلها (من المفردات الثابتة)
            $table->string('role_code', 30);         // الدور المالك — يُشعَر عند وصولها
            $table->string('permission', 60);        // الصلاحية التي تعتمدها
            $table->string('label', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // حالة واحدة لا تتكرّر في السلسلة نفسها — وإلا صارت الحلقة لا نهائية
            $table->unique(['workflow', 'status_key']);
            $table->index(['workflow', 'is_active', 'position']);
        });

        // pending_center: مرحلة مدير المركز — تُضاف للمفردات ولو لم تُفعّل بعد
        $this->setCheck(self::STATUSES);

        // السلسلة الحالية كما هي في الكود، + مدير المركز نهائياً
        $now = now();
        DB::table('workflow_stages')->insert([
            ['workflow' => 'report', 'position' => 1, 'status_key' => 'pending_evaluator',
             'role_code' => 'EVALUATOR', 'permission' => 'report.approve_evaluator',
             'label' => 'اعتماد المقيّم', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['workflow' => 'report', 'position' => 2, 'status_key' => 'pending_manager',
             'role_code' => 'ASSESS_MANAGER', 'permission' => 'report.approve_manager',
             'label' => 'اعتماد مدير إدارة التقييم', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['workflow' => 'report', 'position' => 3, 'status_key' => 'pending_dev_approval',
             'role_code' => 'DEV_MANAGER', 'permission' => 'report.approve',
             'label' => 'اعتماد إدارة تطوير الكفاءات', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['workflow' => 'report', 'position' => 4, 'status_key' => 'pending_center',
             'role_code' => 'CENTER_MANAGER', 'permission' => 'report.approve_center',
             'label' => 'الاعتماد النهائي — مدير المركز', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        // تقارير عند مرحلة مدير المركز لا تمثيل لها في المخطّط القديم — أرجِعها
        // للمرحلة السابقة بدل ترك صفوف تخالف القيد فيفشل التراجع
        DB::table('final_reports')->where('status', 'pending_center')
            ->update(['status' => 'pending_dev_approval']);

        $this->setCheck(array_values(array_diff(self::STATUSES, ['pending_center'])));
        Schema::dropIfExists('workflow_stages');
    }

    private function setCheck(array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));
        DB::statement('ALTER TABLE final_reports DROP CONSTRAINT IF EXISTS final_reports_status_check');
        DB::statement("ALTER TABLE final_reports ADD CONSTRAINT final_reports_status_check CHECK (status::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
