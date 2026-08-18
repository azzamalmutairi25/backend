<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ════════════════════════════════════════════════════════════
//  استقبال الموظفين — جدولان جديدان، لا تعديل على جدولٍ قائم.
//
//  reception_visits      : وصول المشارك إلى المركز في يومٍ بعينه، وتوقيعه
//                          وإقراره بصحّة بياناته.
//  reception_assignments : توزيع الاستقبال للمشارك على نشاطٍ ومقيّم، وقرار
//                          المقيّم فيه (قبول/ردّ). صفوفه سجلٌّ لا يُكتب فوقه:
//                          إعادة الإسناد بعد الردّ تُنشئ صفّاً جديداً ويبقى
//                          المردود بسببه — وإلا ضاع أثر «لماذا غُيِّر المقيّم».
// ════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reception_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->date('visit_date');

            // وقت الوصول يُملأ تلقائياً لحظة التسجيل، ويبقى قابلاً للتعديل:
            // الاستقبال قد يسجّل متأخراً عن الوصول الفعلي بدقائق.
            $table->timestamp('arrived_at')->nullable();

            // التوقيع صورة يرسمها المشارك — بيانات شخصية حيوية، تُخزَّن مشفَّرة
            // كبقية حقول المشارك (Crypt). لا عمود بحث عليها ولا فهرس.
            $table->text('signature_enc')->nullable();
            $table->timestamp('signed_at')->nullable();
            // الإقرار بصحّة البيانات — منفصل عن التوقيع كي يبقى واضحاً أنّ
            // التوقيع تمّ على إقرارٍ مقروء لا على خانةٍ مجهولة
            $table->boolean('attested')->default(false);

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            // arrived → وصل وسُجّل | distributed → وُزّع على نشاطٍ واحد على الأقل
            // approved → اعتمدته العمليات ورُحّلت جلساته إلى الجدول
            $table->string('status', 24)->default('arrived');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // زيارة واحدة لكل دورة في اليوم الواحد — نقرتان على «تسجيل الوصول»
            // لا تُنشئان سجلَّين متنافسين
            $table->unique(['assessment_id', 'visit_date']);
            $table->index(['visit_date', 'status']);
        });

        Schema::create('reception_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('reception_visits')->cascadeOnDelete();
            // interview | discussion | measurement — نفس مفردات schedules.activity
            $table->string('activity', 24);
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();

            // pending → بانتظار قرار المقيّم | accepted → استلمه | rejected → ردّه
            $table->string('status', 24)->default('pending');
            $table->text('reject_reason')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            // الجلسة المُرحَّلة عند الاعتماد — تربط الإسناد بأثره في الجدول
            $table->foreignId('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();

            $table->timestamps();
            $table->index(['evaluator_id', 'status']);
            $table->index(['visit_id', 'status']);
        });

        // إسناد فعّال واحد لكل (زيارة، نشاط): مشارك لا يكون في مقابلتين معاً.
        // جزئي — كي تبقى الإسنادات المردودة في السجلّ ولا تمنع إسناداً بديلاً.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX reception_assignments_active_unique
                ON reception_assignments (visit_id, activity)
                WHERE status IN ('pending', 'accepted')
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_assignments');
        Schema::dropIfExists('reception_visits');
    }
};
