<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// فهارس فريدة تُحكِم النزاهة على مستوى القاعدة (تمنع سباقات check-then-write)
return new class extends Migration
{
    public function up(): void
    {
        // مرشح واحد لكل هوية (يمنع تكرار الاستيراد/الإضافة المتزامن)
        Schema::table('candidates', function (Blueprint $table) {
            $table->unique('national_id_hash', 'candidates_national_id_hash_unique');
        });
        // حضور واحد لكل جلسة (قاعدة المرّة الواحدة على مستوى القاعدة)
        Schema::table('attendance', function (Blueprint $table) {
            $table->unique('schedule_id', 'attendance_schedule_unique');
        });
        // محادثة واحدة لكل كيان
        Schema::table('chat_threads', function (Blueprint $table) {
            $table->unique(['entity_type', 'entity_id'], 'chat_threads_entity_unique');
        });
        // كفاءة تُرصد مرّة واحدة داخل التقييم الواحد
        Schema::table('evaluation_scores', function (Blueprint $table) {
            $table->unique(['evaluation_id', 'competency_id'], 'eval_scores_eval_competency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', fn (Blueprint $t) => $t->dropUnique('candidates_national_id_hash_unique'));
        Schema::table('attendance', fn (Blueprint $t) => $t->dropUnique('attendance_schedule_unique'));
        Schema::table('chat_threads', fn (Blueprint $t) => $t->dropUnique('chat_threads_entity_unique'));
        Schema::table('evaluation_scores', fn (Blueprint $t) => $t->dropUnique('eval_scores_eval_competency_unique'));
    }
};
