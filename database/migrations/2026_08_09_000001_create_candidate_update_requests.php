<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  طلب تحديث بيانات مشارك — يرفعه المستخدم الخارجي حين يكتشف أن المشارك
//  مُضاف مسبقاً، ولا يمسّ السجلّ حتى يعتمده صاحب صلاحية.
//
//  الحمولة (المقترح) واللقطة (الحالي لحظة الطلب) مشفّرتان كتلةً واحدة —
//  نفس نهج candidate_cvs: كلتاهما تحملان الاسم والجوال والبريد والسيرة.
//  اللقطة تُحفظ لأن المعتمِد يقارن «ما كان» بـ«ما يُقترح»، ولا يصحّ أن
//  تتغيّر المقارنة تحته إن عُدّل السجلّ بين الرفع والبتّ.
// ════════════════════════════════════════════════════════════
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_update_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            // مؤشّر الفاعل فقط: حذف المستخدم لا يمحو الطلب ولا سجلّه التدقيقي
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->text('payload_enc');   // Crypt(JSON): { identity: {...}, cv: {...} }
            $table->text('snapshot_enc');  // Crypt(JSON): القيم الحالية لحظة الطلب
            $table->string('note', 500)->nullable();        // مبرّر مقدّم الطلب
            $table->string('review_note', 500)->nullable(); // سبب الرفض / ملاحظة المعتمِد
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('candidate_id');
            $table->index('requested_by');
        });

        // طلب معلّق واحد لكل مشارك. القيد في القاعدة يحسم السباق المتزامن:
        // فحصٌ في التطبيق وحده يسمح لطلبين متزامنين بالمرور فيتعارض اعتمادهما.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX candidate_update_requests_one_pending
                ON candidate_update_requests (candidate_id) WHERE status = 'pending'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_update_requests');
    }
};
