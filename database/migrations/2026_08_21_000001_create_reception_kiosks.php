<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  كشك الاستقبال — جهازٌ لوحيّ في بهو المركز يمرّ عليه المشاركون واحداً
//  بعد واحد: يُدخل المشارك هويته، فيرى بياناته، فيوقّع، فتُطبع بطاقته.
//
//  لماذا رمزٌ في جدول لا جلسةُ مستخدم؟ لأن الجهاز لا يُسجَّل عليه دخول
//  موظّف: جلسةٌ مفتوحة على جهازٍ يمسكه مشاركون متعاقبون هي صلاحيةُ موظّفٍ
//  في يد الجمهور. الرمز بديلها — لا يملك إلا مسارات الكشك الخمسة.
//
//  ونطاقه يومٌ واحد لا مفتوح: رمزٌ دائم على جهازٍ في بهوٍ عام يُصوَّر مرّة
//  فيُفتح من خارج المركز إلى الأبد. وانتهاؤه بانتهاء اليوم يجعل التسريب
//  خسارةَ يومٍ لا خسارةَ باب.
//
//  الرمز وحده لا يكشف شيئاً: من يفتح الرابط يرى حقل رقم هوية فقط، ولا
//  بيان قبل مطابقته — نفس مبدأ بوّابة المشارك (رابط تملكه + هوية تعرفها).
// ════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_kiosks', function (Blueprint $table) {
            $table->id();
            // رمز عشوائي طويل في الرابط — لا يُشتقّ من التاريخ ولا من المعرّف
            $table->string('token', 64)->unique();
            $table->date('kiosk_date');
            // اسم يميّز الجهاز حين تتعدّد الأجهزة («إيباد الاستقبال ١»)
            $table->string('label', 60)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // الإبطال اليدوي: جهازٌ ضاع أو رابطٌ سُرّب يُقفَل فوراً دون انتظار
            // انتهاء اليوم
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['kiosk_date', 'revoked_at']);
        });

        // ── طابور طباعة البطاقات ──
        // المشارك لا يطبع بنفسه: الطابعة عند مسؤول المشاركين، والكشك يسجّل
        // طلباً فقط. فصلُ الطلب عن التنفيذ يجعل انقطاع الطابعة تأخيراً في
        // الطابور لا فشلاً أمام المشارك، ويُبقي البطاقات مطبوعةً بترتيبها.
        Schema::table('reception_visits', function (Blueprint $table) {
            $table->timestamp('badge_requested_at')->nullable()->after('attested');
            $table->timestamp('badge_printed_at')->nullable()->after('badge_requested_at');
            $table->foreignId('badge_printed_by')->nullable()->after('badge_printed_at')
                ->constrained('users')->nullOnDelete();
            // الكشك يسجّل الوصول بلا مستخدم (received_by = null)، وهذا يميّز
            // «سجّل نفسه على الكشك» عن «سجّله موظّف يدوياً» في التقارير
            $table->foreignId('kiosk_id')->nullable()->after('received_by')
                ->constrained('reception_kiosks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reception_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_id');
            $table->dropConstrainedForeignId('badge_printed_by');
            $table->dropColumn(['badge_requested_at', 'badge_printed_at']);
        });
        Schema::dropIfExists('reception_kiosks');
    }
};
