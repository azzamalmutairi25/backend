<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// توسيع أعمدة PII في email_logs لتتّسع للقيم المشفّرة
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->text('to_email')->change();
            $table->text('to_name')->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('to_email', 200)->change();
            $table->string('to_name', 200)->change();
        });
    }
};
