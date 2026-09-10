<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// المستشار يُوسَم بالمجالات الفنية، والمجال يُنسَب لقطاعاته، وللمستشار رمزٌ
// وهوية.
//
// ── لماذا المجالات الفنية لا مجالات الخبرة ──
// في المنصّة مرجعان متوازيان: `expertise_areas` تُوسَم بها حسابات المقيّمين،
// و`technical_areas` يُوسَم بها المشاركون. والمطابقة بينهما كانت **نصّية على
// نثر السيرة**: يُقرأ منصب المشارك ونبذته وإدارته، ويُبحث فيها عن اسم مجال
// خبرة المقيّم بعد تطبيع الهمزات والتاء المربوطة.
//
// مطابقةٌ كهذه تُصيب وتُخطئ بلا أن يعرف أحدٌ أيّهما وقع. والمرجعان يصفان
// الشيء نفسه من طرفين، فيُدمجان: المستشار والمشارك يُوسَمان من **مرجعٍ واحد**،
// والمطابقة تصير تقاطعاً صريحاً يُعدّ ويُعرَض.
//
// ── لماذا المجال يحتاج قطاعاً ──
// «الأدلّة الجنائية» مجالٌ لقطاعٍ بعينه، و«القيادة» تصلح لكل القطاعات. فبلا
// نسبةٍ للقطاع تظهر مجالات كل الجهات في نموذج كل مشارك.
//
// والقائمة الحالية (تسعة وعشرون مجالاً من تصنيف المركز) تُنسَب **لكل
// القطاعات** مبدئياً: هي تصنيفٌ مركزيّ لا قطاعيّ حتى يصل ملفّ صاحب المنصّة
// الذي يوزّعها. وربطُها بقطاعٍ واحد تخميناً أسوأ من تعميمها.
//
// ── هوية المستشار ──
// معرّفٌ شخصيّ مباشر كهوية المشارك، فيُشفَّر مثلها ولا يُخزَّن صريحاً. وله
// بصمةٌ للبحث بمطابقةٍ تامّة — لا لعرضه.
return new class extends Migration
{
    public function up(): void
    {
        // ── المجال ↔ القطاعات ──
        Schema::create('technical_area_sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technical_area_id')->constrained('technical_areas')->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['technical_area_id', 'sector_id']);
            $table->index('sector_id');
        });

        // كل مجالٍ قائم لكل قطاع — تصنيفٌ مركزيّ حتى يوزّعه ملفّ المركز
        $areas = DB::table('technical_areas')->pluck('id');
        $sectors = DB::table('sectors')->pluck('id');
        $now = now();
        $rows = [];
        foreach ($areas as $areaId) {
            foreach ($sectors as $sectorId) {
                $rows[] = [
                    'technical_area_id' => $areaId,
                    'sector_id' => $sectorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('technical_area_sectors')->insert($chunk);
        }

        // ── المستشار ↔ المجالات الفنية ──
        Schema::create('user_technical_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('technical_area_id')->constrained('technical_areas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'technical_area_id']);
            $table->index('technical_area_id');
        });

        // ── رمز المستشار وهويته ──
        Schema::table('users', function (Blueprint $table) {
            // حرفٌ إلى حرفين، فريدٌ على مستوى المنصّة: يُعرَف المستشار به في
            // شبكة الجدولة كما يُعرَف المشارك برمزه. فارغٌ لمن ليس مستشاراً.
            $table->string('code', 2)->nullable()->unique()->after('username');

            // معرّفٌ شخصيّ — مشفَّر كهوية المشارك، وبصمتُه للبحث لا للعرض
            $table->text('national_id_enc')->nullable()->after('full_name');
            $table->string('national_id_hash', 64)->nullable()->after('national_id_enc');
            $table->index('national_id_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['national_id_hash']);
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'national_id_enc', 'national_id_hash']);
        });

        Schema::dropIfExists('user_technical_areas');
        Schema::dropIfExists('technical_area_sectors');
    }
};
