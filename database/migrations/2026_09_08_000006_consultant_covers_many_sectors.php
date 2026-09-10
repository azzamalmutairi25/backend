<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// المستشار يغطّي قطاعاً أو أكثر.
//
// كان `users.sector_id` عموداً واحداً، و`coversSector` تطابقه مطابقةً تامّة —
// فمستشارٌ يخدم قطاعين لا سبيل لتمثيله إلا بحسابين.
//
// ── العمود يبقى ──
// لا يُسقَط ولا يُفرَّغ: هو **القطاع الأساسي** — ما يُعرض في بطاقة المستخدم،
// وما تسقط إليه الشاشات التي تعرض قطاعاً واحداً (الجدول الذهبي، كشف اليوم).
// وإسقاطُه يوجب تعديل خمسةٍ وثلاثين موضعاً في تغييرٍ واحد مع تحويل الحارس
// نفسه — وهما تغييران لا ينبغي أن يجتمعا.
//
// فالجدول يُضيف ولا يستبدل: `sectorIds()` تقرأ الاثنين معاً، والأساسي أوّلها.
//
// ── والفراغ يبقى منعاً لا إذناً ──
// محصورٌ بلا قطاعٍ واحد لا يغطّي شيئاً — بياناتٌ ناقصة لا تُقرأ إذناً مفتوحاً.
// وهذا ما كان عليه العمود، ويبقى عليه الجدول.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sectors', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained('sectors')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'sector_id']);
            $table->index('sector_id');
        });

        // كل من له قطاعٌ اليوم يُنسخ إليه — فلا يتغيّر سلوكُ حسابٍ قائم
        $now = now();
        $rows = DB::table('users')->whereNotNull('sector_id')
            ->get(['id', 'sector_id'])
            ->map(fn ($u) => [
                'user_id' => $u->id,
                'sector_id' => $u->sector_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('user_sectors')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sectors');
    }
};
