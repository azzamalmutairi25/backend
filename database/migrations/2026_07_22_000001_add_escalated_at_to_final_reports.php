<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// وقت تصعيد التقرير المتأخّر — يمنع إعادة التصعيد اليومي غير المحدود
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
