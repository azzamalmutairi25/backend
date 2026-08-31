<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════════════════
//  صندوق الصادر — الجسر بين المنصّة والبحيرة
//
//  الحدث يُكتب هنا في المعاملة نفسها التي غيّرت حالة التقرير، فيصير
//  وصولُه إلى البحيرة مسألةَ زمنٍ لا مسألةَ حظّ: لو سقطت البحيرة أو
//  الشبكة، الصفُّ باقٍ ويُشحن لاحقاً. ولو فشل الاعتماد نفسه، تراجعت
//  المعاملةُ فلم يُكتب حدثٌ لِما لم يحدث.
//
//  البديل — إرسالٌ مباشر داخل الطلب — كان يربط نجاحَ اعتماد تقريرٍ
//  حكوميّ بتوفّر قاعدةٍ ثانية. لا يُقايَض ذلك بأيّ قدرٍ من الطزاجة.
//
//  إضافةٌ صرفة: جدولٌ جديد لا يمسّ جدولاً قائماً. إسقاطُه يُعيد المنصّة
//  إلى ما كانت عليه بالضبط.
// ════════════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_lake_outbox', function (Blueprint $t) {
            $t->bigIncrements('id');

            // اشتقاقيّ (UUIDv5) لا عشوائيّ: إعادةُ تشغيل التعبئة التاريخية
            // بعد فشلٍ جزئيّ لا تُنتج حدثاً جديداً لِما سبق إرساله.
            $t->uuid('event_uuid')->unique();

            // يُختم مرّةً واحدة هنا ولا يُعاد ختمُه عند الشحن. البحيرة
            // مُجزّأة به ومفتاحُها الفريد يتضمّنه — فلو تغيّر عند إعادة
            // المحاولة لَتضاعف الحدثُ بصمت بدل أن يُمتصّ.
            $t->timestampTz('occurred_at');

            $t->string('event_type', 40);
            $t->string('subject_type', 20)->default('report');

            $t->unsignedBigInteger('source_report_id')->nullable();
            $t->unsignedBigInteger('source_assessment_id')->nullable();

            // معرّفٌ بديل فقط. لا معرّف المشارك، ولا رقم الهوية، ولا تجزئتُه.
            $t->char('person_ref', 64)->nullable();
            $t->string('participant_code', 20)->nullable();
            $t->unsignedBigInteger('sector_id')->nullable();

            // يُحمل ليُفحص قبل الشحن. المُصنَّف لا يُكتب هنا أصلاً، وهذا
            // العمود شاهدٌ ثانٍ لا مصدرُ قرار.
            $t->string('classification', 20)->default('normal');

            // تعذّر بناء الحمولة كاملةً: يُحفظ الهيكل ولا يُفقد الحدث.
            $t->boolean('degraded')->default(false);

            $t->json('payload');
            $t->char('payload_sha256', 64);
            $t->integer('payload_bytes');

            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestampTz('shipped_at')->nullable();

            // الحجر الصحّي: صفٌّ رفضته البحيرة مراراً يُعزل بدل أن يُوقف
            // التغذية إلى الأبد. صفٌّ واحدٌ تالف لا يُسكت المنصّةَ كلَّها.
            $t->timestampTz('failed_at')->nullable();

            $t->timestampTz('created_at')->useCurrent();

            // مسار الشحن: غيرُ المشحون وغيرُ المعزول، بترتيب الإصدار.
            $t->index(['shipped_at', 'failed_at', 'id'], 'outbox_pending_idx');
            $t->index('source_assessment_id', 'outbox_assessment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_lake_outbox');
    }
};
