<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  هجرة: المرشحون، الكفاءات، التقييمات
// ════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── المرشحون ──
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('participant_code', 20)->unique();  // DA-001
            // البيانات الحساسة (مشفّرة عبر Laravel Crypt في الموديل)
            $table->text('national_id_enc');                   // رقم الهوية مشفّر
            $table->text('full_name_enc');                     // الاسم مشفّر
            $table->text('mobile_enc')->nullable();            // الجوال مشفّر
            $table->string('email', 200)->nullable();
            // بيانات غير حساسة
            $table->foreignId('sector_id')->constrained('sectors');
            $table->string('rank_label', 50);                  // عقيد، م-14
            $table->enum('tier', ['upper', 'middle'])->nullable();
            $table->enum('assessment_type', ['comprehensive', 'executive'])->default('comprehensive');
            $table->enum('status', ['draft', 'scheduled', 'assessed', 'approved', 'completed'])->default('draft');
            $table->timestamps();

            $table->index('status');
            $table->index('sector_id');
        });

        // ── الكفاءات ──
        Schema::create('competencies', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->enum('type', ['behavioral', 'leadership', 'technical']);
            $table->integer('max_level')->default(5);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // ── الجداول (المواعيد) ──
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->date('schedule_date');
            $table->time('schedule_time')->nullable();
            $table->enum('activity', ['interview', 'discussion', 'measurement', 'integration']);
            $table->foreignId('evaluator_id')->nullable()->constrained('users');
            $table->foreignId('assistant_id')->nullable()->constrained('users');
            $table->string('location', 200)->nullable();
            $table->timestamps();
        });

        // ── الحضور ──
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->enum('status', ['pending', 'present', 'absent_excused', 'absent_unexcused'])->default('pending');
            $table->timestamp('check_in_time')->nullable();
            $table->text('absence_reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // ── التقييمات ──
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users');
            $table->enum('activity', ['interview', 'discussion']);
            $table->enum('status', ['draft', 'submitted', 'approved', 'returned'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // ── درجات الكفاءات ──
        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('evaluations')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('competencies');
            $table->integer('score');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // ── نتائج أدوات القياس ──
        Schema::create('measurement_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->decimal('personality_score', 5, 2)->nullable();
            $table->decimal('analytical_score', 5, 2)->nullable();
            $table->decimal('english_score', 5, 2)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_results');
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('attendance');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('competencies');
        Schema::dropIfExists('candidates');
    }
};
