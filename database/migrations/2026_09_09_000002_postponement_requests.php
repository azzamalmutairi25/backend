<?php

use App\Security\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// طلب تأجيل — الاستقبال يرفع، ومسؤول الجدولة يبتّ.
//
// ── لماذا طلبٌ لا فعل ──
// «التأجيل قرارُ مسؤول الجدولة لا الاستقبال»: الفترة المعتمَدة مقفلةٌ على
// الاستقبال، ومن يفكّها صاحبها. والاستقبال هو من يرى السبب بعينه — مشاركٌ
// أمامه لا يستطيع إتمام محطّته — فيرفعه، ولا يبتّ فيه.
//
// ── ولماذا جدولٌ مستقلّ ──
// الطلب ليس صفةً في الجلسة: له طالبٌ ووقتٌ وسبب، ثم بتٌّ له صاحبٌ ووقتٌ
// وتعليل. وحشرُه في عمودٍ على `schedules` يفقد نصفه، ويجعل طلبين على جلسةٍ
// واحدة مستحيلين — وهما يقعان حين يُرفَض الأوّل.
//
// ── وأربعة قرارات لا اثنان ──
// «يقبل · يرفض · يُرجع المشارك للمرحلة الناقصة · أو يعيد جدولتها». القبول
// وحده يترك المشارك معلّقاً بلا موعد، وإعادةُ الجدولة تُنشئ له موعداً في
// القرار نفسه — والفرق بينهما هو ما يُدار.
return new class extends Migration
{
    private const STATUSES = ['pending', 'accepted', 'rejected', 'returned', 'rescheduled'];

    public function up(): void
    {
        Schema::create('postponement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained('assessments')->nullOnDelete();
            // الجلسة المطلوب تأجيلها — قد تسقط بإعادة جدولةٍ لاحقة فلا تُقيَّد
            $table->foreignId('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            // المحطّة تُحفظ نصّاً: الجلسة قد تُحذف، والمحطّة هي ما يُسأل عنه
            $table->string('station', 20)->nullable();

            $table->text('reason');                       // بيد الاستقبال — إلزاميّ
            $table->string('status', 16)->default('pending');
            $table->text('decision_note')->nullable();    // بيد الجدولة
            $table->date('new_date')->nullable();         // مع «أُعيدت جدولتها»

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('candidate_id');
        });

        DB::statement("ALTER TABLE postponement_requests ADD CONSTRAINT postponement_requests_status_check
            CHECK (status IN ('".implode("','", self::STATUSES)."'))");

        // طلبٌ معلّقٌ واحدٌ لكل جلسة: طلبان معلّقان على الجلسة نفسها يجعلان
        // البتّ في أحدهما لا يعني شيئاً للآخر. والمبتوت لا يمنع طلباً جديداً.
        DB::statement("CREATE UNIQUE INDEX postponement_one_pending_per_schedule
            ON postponement_requests (schedule_id) WHERE status = 'pending' AND schedule_id IS NOT NULL");

        // ── الصلاحيتان ──
        // الرفع عند الاستقبال، والبتّ عند الجدولة — وهو جوهر القاعدة: من يرى
        // السبب ليس من يفكّ الفترة.
        $now = now();
        foreach ([
            'RECEPTIONIST' => Permissions::POSTPONE_REQUEST,
            'SCHEDULER' => Permissions::POSTPONE_DECIDE,
        ] as $roleCode => $perm) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if (! $roleId) {
                continue;
            }
            $has = DB::table('role_permissions')->where('role_id', $roleId)
                ->where('permission', $perm)->exists();
            if (! $has) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId, 'permission' => $perm,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', [
            Permissions::POSTPONE_REQUEST, Permissions::POSTPONE_DECIDE,
        ])->delete();

        Schema::dropIfExists('postponement_requests');
    }
};
