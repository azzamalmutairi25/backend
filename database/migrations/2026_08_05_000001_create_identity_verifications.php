<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// سجلّ محاولات التحقق من الهوية — أثر تدقيقي: من تحقّق، ومتى، والنتيجة.
// لا يُخزَّن رقم الهوية الخام (بيانات حسّاسة) — الربط بالمرشّح عبر candidate_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->nullOnDelete();
            // matched | not_matched | failed | dev_mode
            $table->string('status', 20);
            $table->string('provider', 40)->nullable();
            $table->string('detail', 255)->nullable(); // رسالة موجزة بلا بيانات حسّاسة
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['candidate_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
