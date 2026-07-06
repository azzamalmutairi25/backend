<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  هجرة: التقارير، الإشعارات، الشات، السجلات
// ════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── التقارير النهائية ──
        Schema::create('final_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->decimal('behavioral_fit', 5, 2)->nullable();
            $table->decimal('technical_fit', 5, 2)->nullable();
            $table->string('recommendation', 100)->nullable();
            $table->text('overview_text')->nullable();
            $table->json('strengths')->nullable();
            $table->json('development_areas')->nullable();
            $table->enum('status', ['draft', 'pending_dev_approval', 'approved', 'returned'])->default('draft');
            // حقول الإرجاع
            $table->text('return_reason')->nullable();
            $table->integer('return_count')->default(0);
            $table->foreignId('last_returned_by')->nullable()->constrained('users');
            $table->timestamp('last_returned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // ── الإشعارات ──
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['info', 'action', 'approval', 'return', 'report', 'system'])->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('entity_type', 50)->nullable();
            $table->string('entity_id', 80)->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['recipient_id', 'is_read']);
        });

        // ── سلاسل المحادثات ──
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50);          // report, candidate
            $table->unsignedBigInteger('entity_id');
            $table->string('title', 200)->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });

        // ── رسائل المحادثة ──
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('message');
            $table->enum('message_type', ['comment', 'action', 'system'])->default('comment');
            $table->string('action_type', 30)->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'created_at']);
        });

        // ── سجل البريد ──
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_email', 200);
            $table->string('to_name', 200)->nullable();
            $table->string('subject', 300);
            $table->text('body')->nullable();
            $table->enum('email_type', ['invitation', 'reminder', 'notification', 'result'])->default('notification');
            $table->foreignId('candidate_id')->nullable()->constrained('candidates');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // ── سجل الرسائل النصية ──
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_mobile', 20);
            $table->string('message', 500);
            $table->enum('sms_type', ['invitation', 'reminder', 'notification', 'otp'])->default('notification');
            $table->foreignId('candidate_id')->nullable()->constrained('candidates');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->string('provider_ref', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // ── سجل التدقيق ──
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('action', 80);
            $table->string('entity_type', 50)->nullable();
            $table->string('entity_id', 80)->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('action');
        });

        // ── الإعدادات ──
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->string('description', 300)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('final_reports');
    }
};
