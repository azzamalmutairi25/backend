<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// توسيع to_mobile ليتّسع للقيمة المشفّرة (تشفير PII في سجل الرسائل)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->text('to_mobile')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('to_mobile', 20)->change();
        });
    }
};
