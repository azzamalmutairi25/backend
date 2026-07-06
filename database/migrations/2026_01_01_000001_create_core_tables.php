<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  الهجرة الأساسية: الأدوار، القطاعات، المستخدمون
// ════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── الأدوار ──
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();      // ADMIN, SCHEDULER, ...
            $table->string('name_ar', 100);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // ── القطاعات ──
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();       // DA, HI, MA, ...
            $table->string('name_ar', 100);
            $table->boolean('is_military')->default(false);
            $table->timestamps();
        });

        // ── المستخدمون ──
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 80)->unique();
            $table->string('full_name', 200);
            $table->string('email', 200)->nullable();
            $table->string('password');
            $table->foreignId('role_id')->constrained('roles');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // ── جلسات قاعدة البيانات ──
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('sectors');
        Schema::dropIfExists('roles');
    }
};
