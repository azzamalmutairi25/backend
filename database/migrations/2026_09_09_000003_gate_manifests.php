<?php

use App\Security\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// بيان تصاريح الدخول — بيانٌ يوميّ جماعيّ يحلّ محلّ التصاريح الفردية.
//
// ── لماذا تحوّل من ورقةٍ لكل شخص إلى بيانٍ واحد ──
// الحارس عند البوّابة لا يفتّش عن ورقةِ كلِّ قادم: يقرأ كشفاً بمن يُنتظرون
// ويطابق. وأربعون ورقةً تُطبع وتُوزَّع وتُفقَد إحداها، والبيان ورقةٌ واحدة.
//
// ── ولماذا يُعتمَد قبل أن يُطبع ──
// البيان يفتح باب المركز لأسماءٍ بأرقام هوياتهم. فلا يخرج بلا اعتمادٍ من
// مدير المركز، ويُطبع مختوماً باسمه وتاريخ اعتماده — فيُعرف من أذِن.
//
// ── ولماذا صفوفُه تُثبَّت لا تُشتقّ ──
// لو قُرئت من الجلسات وقت الطباعة لتغيّر البيان بعد اعتماده: جلسةٌ تُضاف
// فيدخل من لم يُعتمَد اسمُه. الصفوف تُلتقَط حين يُجهَّز، والاعتماد يقع على
// ما التُقط.
//
// ── والرمز لا يظهر فيه ──
// أداةٌ داخلية لا يعرفها الحارس، والمطابقة باسمٍ وهوية. أمّا الهوية مطبوعةً
// فلها سابقةٌ قائمة: كشف الحضور المطبوع فيه عمود «الهوية الوطنية» بشرطٍ
// وصلاحية — والبيان يتبع عرفاً لا يخترع سابقة.
return new class extends Migration
{
    private const STATUSES = ['draft', 'pending', 'approved'];

    public function up(): void
    {
        Schema::create('gate_manifests', function (Blueprint $table) {
            $table->id();
            $table->date('manifest_date');
            // الموعد يضعه موظّف الاستقبال يدوياً ليتّفق مع خطاب القطاع — لا
            // يُشتقّ من أبكر جلسة: الخطاب يقول «الثامنة والنصف» والجلسة ٠٩:٠٠
            // ٦٠ لا ٢٠: الموعد يُكتب بالعربية نثراً — «الثامنة والنصف صباحاً»
            // وحدها تسعةَ عشرَ محرفاً، وحدٌّ ضيّق يردّ ما يكتبه الموظّف طبيعياً
            $table->string('gate_time', 60)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('status', 12)->default('draft');
            $table->string('note', 300)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // بيانٌ واحدٌ لليوم — «الدورية: بيانٌ يوميّ»
            $table->unique('manifest_date');
        });

        DB::statement("ALTER TABLE gate_manifests ADD CONSTRAINT gate_manifests_status_check
            CHECK (status IN ('".implode("','", self::STATUSES)."'))");

        Schema::create('gate_manifest_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained('gate_manifests')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['manifest_id', 'candidate_id']);
        });

        // ── الصلاحيتان ──
        // الإعداد عند الاستقبال والاعتماد عند مدير المركز: من يُخرج الأسماء
        // ليس من يأذن بإخراجها.
        $now = now();
        foreach ([
            'RECEPTIONIST' => Permissions::GATE_MANIFEST_MANAGE,
            'CENTER_MANAGER' => Permissions::GATE_MANIFEST_APPROVE,
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
            Permissions::GATE_MANIFEST_MANAGE, Permissions::GATE_MANIFEST_APPROVE,
        ])->delete();

        Schema::dropIfExists('gate_manifest_entries');
        Schema::dropIfExists('gate_manifests');
    }
};
