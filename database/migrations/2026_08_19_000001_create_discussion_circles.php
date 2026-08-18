<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// حلقة النقاش ككيان — «تحديد المقابلات وحلقة النقاش للمقيمين».
//
// المقابلة جلسةٌ لمشاركٍ واحد، أمّا حلقة النقاش فجلسةٌ **لمجموعة**: مستشارٌ
// ومساعدُه وعدّةُ مشاركين في وقتٍ واحد. تمثيلها بصفوف `schedules` منفصلة يعمل
// (وكذلك كانت تُنشأ) لكنه لا يعبّر عن السعة: لا شيء يمنع إسناد اثني عشر مشاركاً
// إلى حلقةٍ تتّسع لستّة، ولا شيء يمنع حجز المستشار نفسه في حلقتين متزامنتين.
//
// ── قراران تُرِكا إعداداً لا تخميناً ──
//
// **السعة**: عمودٌ لكل حلقة، وقيمته الافتراضية من الإعداد
// `discussion.default_circle_capacity`. فالمركز يضبط رقمه مرّة، والحلقة
// الاستثنائية تتجاوزه — ولا رقمَ محفورٌ في الشيفرة.
//
// **العلاقة بمجموعتَي الكشف (أ/ب)**: `group_letter` **قابل للإفراغ**. إن كانت
// الحلقة تقابل المجموعة واحدةً بواحدة يُملأ الحرف، وإن كانتا مستقلّتين يُترك
// فارغاً. أيّ الاحتمالين صحّ لا يحتاج هجرةً ثانية.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_circles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->nullable()->constrained('scheduling_periods')->nullOnDelete();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();

            $table->date('circle_date');
            $table->time('circle_time');
            $table->string('location', 200)->nullable();

            // مستشار الحلقة ومساعده — قابلان للإفراغ: تُبنى الحلقة أولاً ثم يُسنَد
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assistant_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedSmallInteger('capacity');
            $table->string('group_letter', 1)->nullable();   // A | B — أو فارغ

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // مستشارٌ واحد لا يقع في حلقتين في اللحظة نفسها — القيد هو الحارس،
            // لا فحصٌ في الكود تسبقه ضغطتان متزامنتان. جزئيٌّ لأن المستشار
            // قابل للإفراغ، وnull في postgres لا يتصادم مع null.
            $table->index(['circle_date', 'circle_time']);
            $table->index(['period_id', 'circle_date']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX discussion_circles_evaluator_slot_unique
             ON discussion_circles (evaluator_id, circle_date, circle_time)
             WHERE evaluator_id IS NOT NULL'
        );

        // الجلسة ← حلقتها. قابل للإفراغ: كل جلسة نقاشٍ أُنشئت قبل اليوم بلا حلقة،
        // وتبقى تعمل كما هي. nullOnDelete لا cascade: حذف الحلقة لا يمحو جلساتٍ
        // جرى فيها حضورٌ وتقييم — تفقد انتماءها لا وجودها.
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignId('circle_id')->nullable()->after('period_id')
                ->constrained('discussion_circles')->nullOnDelete();
            $table->index('circle_id');
        });

        // السعة الافتراضية — إعدادٌ يضبطه المركز، تتجاوزه كل حلقة بقيمتها
        DB::table('settings')->updateOrInsert(
            ['key' => 'discussion.default_circle_capacity'],
            ['value' => '6', 'description' => 'السعة الافتراضية لحلقة النقاش (عدد المشاركين)']
        );
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex(['circle_id']);
            $table->dropConstrainedForeignId('circle_id');
        });
        Schema::dropIfExists('discussion_circles');
        DB::table('settings')->where('key', 'discussion.default_circle_capacity')->delete();
    }
};
