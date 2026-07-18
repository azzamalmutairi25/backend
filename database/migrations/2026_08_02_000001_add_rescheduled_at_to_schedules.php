<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// يستهلك الغياب مرّة واحدة: إعادة جدولته تضع rescheduled_at، فلا يُعاد إنشاء جلسة
// مكرّرة عبر نداء متكرّر/متزامن (لم يكن ثمّة عمود يستهلك الغياب سابقاً).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->timestamp('rescheduled_at')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('rescheduled_at');
        });
    }
};
