<?php

use App\Security\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// مراجعة السيرة عند الاستقبال: تُصحَّح عند المكتب، وتُعتمد، ثم تُجمَّد.
//
// ── لماذا سجلُّ إصدارات ──
// `candidate_cvs.version` عدّادُ قفلٍ تفاؤليّ: يقول «تغيّرت» ولا يقول **ماذا**
// تغيّر ولا **من** غيّره. والسيرة تُصحَّح عند مكتب الاستقبال قبيل التقييم
// مباشرةً، فحقلٌ صُحّح خطأً يصل المستشار ولا أثر يدلّ عليه. الإصدار يحفظ
// الوثيقة كاملةً بعد التغيير ومعها أسماءُ ما تغيّر، فيُقرأ الفرق لا يُستنتج.
//
// ── ولماذا الاعتماد على الزيارة لا على السيرة ──
// السيرة ملفٌّ واحدٌ للمشارك عبر السنين، والاعتماد فعلُ **يومٍ بعينه**: هذا
// ما رآه الموظّف وأقرّه في زيارة اليوم. وضعُه على السيرة يجعل اعتماد اليوم
// يسري على زيارةٍ بعد سنة.
//
// ── والتعديل ينقض الاعتماد ──
// اعتمادٌ يبقى بعد تغيير ما اعتُمد يشهد على نصٍّ لم يُقرأ. فكل تعديلٍ يُصفّر
// `cv_approved_at` — يُعاد الاعتماد أو لا يُرسَل.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_cv_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            // الوثيقة كاملةً **بعد** التغيير — مشفّرة كأصلها. حفظُ الفرق وحده
            // يجعل قراءة إصدارٍ قديم تركيباً لسلسلةٍ كاملة، وأيُّ قيدٍ ناقصٍ
            // فيها يُفسد كلَّ ما بعده.
            $table->text('cv_data_enc')->nullable();
            // أسماء الحقول التي تغيّرت — تُقرأ في سير الأحداث بلا فكّ تشفير
            $table->json('changed_fields')->nullable();
            $table->string('source', 12)->default('admin'); // portal | admin | reception | import
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            // إصدارٌ واحد لكل رقم — يحسم سباق كتابتين على السيرة نفسها
            $table->unique(['candidate_id', 'version']);
            $table->index(['candidate_id', 'created_at']);
        });

        Schema::table('reception_visits', function (Blueprint $table) {
            $table->timestamp('cv_approved_at')->nullable()->after('attested');
            $table->foreignId('cv_approved_by')->nullable()->after('cv_approved_at')
                ->constrained('users')->nullOnDelete();
            // لحظة إرسال القوائم للمستشارين — وعندها تُجمَّد السيرة
            $table->timestamp('sent_at')->nullable()->after('approved_by');
            $table->foreignId('sent_by')->nullable()->after('sent_at')
                ->constrained('users')->nullOnDelete();
        });

        // ── صلاحيتان ضيّقتان لموظّف الاستقبال ──
        // منفصلتان لأنهما فعلان مختلفان: التصحيح يمسّ النصّ، والاعتماد يفتح
        // بابَ البطاقة والإرسال. جمعُهما يمنع المركز من فصلهما غداً بلا تعديل
        // برمجي — وهما اليوم بيدٍ واحدة بقرار صاحب المنصّة.
        $receptionist = DB::table('roles')->where('code', 'RECEPTIONIST')->value('id');
        if ($receptionist) {
            $now = now();
            $grant = [Permissions::RECEPTION_CV_EDIT, Permissions::RECEPTION_CV_APPROVE];
            $held = DB::table('role_permissions')->where('role_id', $receptionist)
                ->pluck('permission')->all();
            $rows = [];
            foreach (array_diff($grant, $held) as $perm) {
                $rows[] = ['role_id' => $receptionist, 'permission' => $perm,
                    'created_at' => $now, 'updated_at' => $now];
            }
            if ($rows) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', [
            Permissions::RECEPTION_CV_EDIT, Permissions::RECEPTION_CV_APPROVE,
        ])->delete();

        Schema::table('reception_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cv_approved_by');
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['cv_approved_at', 'sent_at']);
        });

        Schema::dropIfExists('candidate_cv_revisions');
    }
};
