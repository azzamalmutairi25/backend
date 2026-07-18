<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// فهارس أداء على الأعمدة الساخنة (FK/تصفية/فرز) التي لا يُفهرسها Postgres تلقائياً.
// تُسرّع التحليلات (الخريطة الحرارية/الاتجاهات/المقارنات) والتقارير والجدولة والحضور.
// إضافة فهرس لا تُغيّر السلوك — سرعةً فقط. أسماء صريحة تفادياً للتصادم، وفحصٌ قبل الإنشاء.
return new class extends Migration
{
    // [table => [ [column(s), indexName], ... ]]
    private array $indexes = [
        'schedules' => [
            ['candidate_id', 'schedules_candidate_id_index'],
            ['schedule_date', 'schedules_schedule_date_index'],
            ['evaluator_id', 'schedules_evaluator_id_index'],
        ],
        'final_reports' => [
            ['status', 'final_reports_status_index'],
            ['candidate_id', 'final_reports_candidate_id_index'],
        ],
        'evaluations' => [
            ['candidate_id', 'evaluations_candidate_id_index'],
            ['evaluator_id', 'evaluations_evaluator_id_index'],
            ['status', 'evaluations_status_index'],
        ],
        'evaluation_scores' => [
            ['competency_id', 'evaluation_scores_competency_id_index'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($defs) {
                foreach ($defs as [$columns, $name]) {
                    $t->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $defs) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($defs) {
                foreach ($defs as [$columns, $name]) {
                    $t->dropIndex($name);
                }
            });
        }
    }
};
