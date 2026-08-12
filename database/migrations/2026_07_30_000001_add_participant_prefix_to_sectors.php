<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// رمز المشارك لكل قطاع قابل للتحديد. كان مشتقّاً من substr(code,0,2) محفوراً،
// فلا يتغيّر إلا بتعديل رمز القطاع نفسه — وهو مفتاح مرجعي لا يُلمس.
// prefix منفصل: يُحرّر من الإعدادات، والأرقام تبقى تلقائية بعده.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->string('participant_prefix', 4)->nullable()->after('code');
        });

        // البادئة الحالية = أول حرفين من الرمز، حفاظاً على الرموز القائمة (DA-001…)
        foreach (DB::table('sectors')->get() as $s) {
            DB::table('sectors')->where('id', $s->id)
                ->update(['participant_prefix' => strtoupper(substr($s->code, 0, 2))]);
        }
    }

    public function down(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->dropColumn('participant_prefix');
        });
    }
};
