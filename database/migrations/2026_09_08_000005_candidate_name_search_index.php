<?php

use App\Models\Candidate;
use App\Services\NameIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// فهرسٌ أعمى للبحث بالاسم — والاسم يبقى مشفَّراً كما هو.
//
// البحث كان بالرمز وحده، لأن الاسم مشفَّرٌ ولا يُبحث في مشفَّر. وصار المطلوب
// بحثاً **بجزء الكلمة**، وهو ما يمنع الحلول السهلة: بصمةُ الاسم الكامل تُطابق
// تامّاً، وبصمةُ كل كلمةٍ لا تجد «محم» في «محمد».
//
// فيُحفَظ لكل مشاركٍ **بصماتُ مقاطع اسمه الثلاثية** بعد تطبيعه. والاسم نفسه
// لا يُخزَّن صريحاً في أي موضع — والبحث يضيّق بالفهرس ثم يؤكّد بفكّ تشفير
// المرشَّحين وحدهم.
//
// ── الكلفة ──
// نحو اثنتي عشرة بصمة لكل اسم. خمسون ألف مشارك ⟵ ستّمئة ألف صفّ من عمودين،
// وهو لا شيء على Postgres مع الفهرس على `gram`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_name_grams', function (Blueprint $table) {
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            // بصمة المقطع — sha256 بست وستين خانة
            $table->string('gram', 64);

            // البحث يبدأ من المقطع وينتهي بالمشارك: الفهرس على هذا الترتيب
            $table->index(['gram', 'candidate_id']);
            $table->index('candidate_id');
            $table->primary(['candidate_id', 'gram']);
        });

        // ── البذر من الأسماء القائمة ──
        // بلا هذا لا يجد البحث إلا من أُضيف بعد الترقية. وعلى دفعات: قاعدة
        // إنتاجٍ كبيرة لا تُحمَّل كاملةً في الذاكرة، وكل اسمٍ يُفكّ تشفيره.
        Candidate::select('id', 'full_name_enc')->chunkById(500, function ($chunk) {
            $rows = [];
            foreach ($chunk as $candidate) {
                $name = $candidate->full_name;
                if (! $name) {
                    continue;
                }
                foreach (NameIndex::grams($name) as $gram) {
                    $rows[] = ['candidate_id' => $candidate->id, 'gram' => $gram];
                }
            }
            foreach (array_chunk($rows, 1000) as $batch) {
                DB::table('candidate_name_grams')->insert($batch);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_name_grams');
    }
};
