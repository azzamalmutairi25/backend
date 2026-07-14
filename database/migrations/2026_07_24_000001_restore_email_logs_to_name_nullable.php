<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// إرجاع email_logs.to_name إلى nullable.
//
// 2026_07_16_000002 وسّع العمود بـ `$table->text('to_name')->change()` بلا ->nullable()،
// و change() يعيد تعريف العمود من الصفر فأسقط الخاصية بصمت. التعريف الأصلي في
// 2026_01_01_000003 كان `->nullable()`، وsendInvitationEmail يمرّر اسماً فارغاً
// دائماً — فصار كل إرسال دعوة بالبريد ينتهي بانتهاك NOT NULL ثم 500.
//
// to_email وto_mobile وmessage كانت NOT NULL في الأصل، فتُترك كما هي.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->text('to_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->text('to_name')->change();
        });
    }
};
