<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// توسيع message ليتّسع للقيمة المشفّرة — VARCHAR(500) يفيض بنص التأكيد المشفّر فيعطّل SMS نهائياً
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->text('message')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->string('message', 500)->change();
        });
    }
};
