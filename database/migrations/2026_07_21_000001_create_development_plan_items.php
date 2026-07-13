<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// خطة التطوير الفردية — بنود قابلة للمتابعة مشتقّة من «مجالات التطوير» في التقرير
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained('assessments')->nullOnDelete();
            $table->text('area');                                   // مجال التطوير
            $table->text('action')->nullable();                     // الإجراء/التوصية
            $table->date('target_date')->nullable();                // الموعد المستهدف
            $table->enum('status', ['pending', 'in_progress', 'done'])->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_plan_items');
    }
};
