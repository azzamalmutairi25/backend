<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// الملخّص التنفيذي النهائي — يكتبه مدير المركز وحده (صلاحية report.exec_summary
// القابلة للتفويض عبر استثناءات الصلاحيات). يُعرض في المستند المختصر.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            $table->text('executive_summary')->nullable()->after('overview_text');
            $table->foreignId('exec_summary_by')->nullable()->after('executive_summary')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('exec_summary_at')->nullable()->after('exec_summary_by');
        });
    }

    public function down(): void
    {
        Schema::table('final_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exec_summary_by');
            $table->dropColumn(['executive_summary', 'exec_summary_at']);
        });
    }
};
