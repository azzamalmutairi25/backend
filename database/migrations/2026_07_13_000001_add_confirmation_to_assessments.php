<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تأكيد المرشح + تسجيل الوصول الذاتي عبر رابط فريد في الرسالة النصية
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->string('confirm_token', 64)->nullable()->unique()->after('status');
            $table->timestamp('confirmed_at')->nullable()->after('confirm_token');
            $table->timestamp('arrived_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['confirm_token', 'confirmed_at', 'arrived_at']);
        });
    }
};
