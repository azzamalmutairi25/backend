<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// حقلان على المشارك: جهة عمله، وحاله الوظيفي.
//
// ── جهة العمل ──
// «متعاقد» صار «قطاع خاص»، وفئةٌ بهذا الاسم تحتاج أن تُجيب: أيّ جهة؟ فمنهم
// من يعمل **داخل قطاع** من التسعة عشر (متعاقدٌ في الدفاع المدني)، ومنهم من
// يتبع جهةً خارجية لا تُدرَج في قائمة القطاعات ولا ينبغي أن تُدرَج — إدراجُها
// يُنفخ قائمةً تدخل في فلاتر المشاركين وفي تغطية المستشارين للقطاعات.
//
// فالحقل نصٌّ حرّ يُملأ للقطاع الخاص وحده، ويبقى فارغاً لغيره. وبه تُجمَّع
// خطابات القطاع الخاص: من أُسنِد لقطاعٍ حقيقي يظهر في خطابه، ومن كُتبت له
// جهةٌ نصّاً يظهر في خطاب القطاع الخاص.
//
// ── الحالة الوظيفية ──
// «على رأس العمل» أو «متقاعد» — صفةٌ للشخص لا لدورته، ولا علاقة لها بحالة
// المشارك في المسار (مسودّة/مجدول/تمّ تقييمه). سُمّيت `employment_status`
// كاملةً لا `status` كي لا تلتبس بها في استعلامٍ أو شاشة.
//
// والافتراض «على رأس العمل»: هو حال الغالبية، ومشاركٌ بلا قيمةٍ صريحة أقربُ
// إليه من التقاعد. ويعدّلها مسؤول الجدولة وحده.
return new class extends Migration
{
    private const STATUSES = ['active', 'retired'];

    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            // جهة العمل — للقطاع الخاص وحده، وفارغةٌ لغيره
            $table->string('employer', 200)->nullable()->after('sector_id');

            // على رأس العمل | متقاعد
            $table->string('employment_status', 12)->default('active')->after('employer');
        });

        $this->setCheck(self::STATUSES);

        // فهرسٌ للفلترة: الشاشة تفلتر بالحال الوظيفي كما تفلتر بالقطاع والجنس
        Schema::table('candidates', function (Blueprint $table) {
            $table->index('employment_status');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE candidates DROP CONSTRAINT IF EXISTS candidates_employment_status_check');
        }

        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['employment_status']);
            $table->dropColumn(['employer', 'employment_status']);
        });
    }

    private function setCheck(array $values): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement('ALTER TABLE candidates DROP CONSTRAINT IF EXISTS candidates_employment_status_check');
        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_employment_status_check CHECK (employment_status::text = ANY (ARRAY[{$list}]::text[]))");
    }
};
