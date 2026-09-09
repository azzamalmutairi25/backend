<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// محطّات كل دورة — والاكتمال يُقاس عليها لا على ثلاثٍ محفورة.
//
// ── ما كان يقع ──
// أوّلُ تقييمٍ يُرسَل يقلب المشارك إلى «تمّ تقييمه»، ويُفتح بابُ التقرير.
// فمن أدّى المقابلة وحدها صار مكتملاً وكُتب تقريرُه على ثلثِ صورة.
//
// ── وما يقع الآن ──
// لكل دورةٍ محطّاتُها المختارة — واحدةٌ أو أكثر، والافتراضي الثلاث — ولا يصير
// «تمّ تقييمه» حتى تتمّ كلُّها. ومن طُلب له تقييمٌ جزئيّ لا يبقى ناقصاً للأبد:
// اكتمالُه يُقاس على ما اختير له وحده.
//
// ── والردم من الواقع ──
// المحطّات المُسنَدة فعلاً هي المختارة. ومن لا جلسة له تُعطى الثلاث — وهو
// الافتراضي. وردمُ الثلاث للجميع كان يُجمّد من نُوي له تقييمٌ جزئيّ في
// «ناقص» لا مخرج منه.
//
// ── ولا أحد يُحطّ عن حالته ──
// من صار «تمّ تقييمه» يبقى: الهجرة تكتب المحطّات ولا تمسّ حالةً واحدة.
// والقاعدة الجديدة تسري على ما هو آتٍ، فلا يُبطَل تقريرٌ قائم بأثرٍ رجعيّ.
return new class extends Migration
{
    private const STATIONS = ['interview', 'discussion', 'measurement'];

    public function up(): void
    {
        Schema::create('assessment_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->string('station', 20);
            // ترتيب المرور — يختاره الاستقبال لكل مشارك، ولا ترتيب محفور
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['assessment_id', 'station']);
        });

        // المفردات حارسٌ في القاعدة لا في الشيفرة وحدها: نشاطٌ رابع يتسلّل
        // من استيرادٍ أو تصحيحٍ يدويّ يجعل دورةً لا تكتمل أبداً بلا رسالة
        DB::statement("ALTER TABLE assessment_stations ADD CONSTRAINT assessment_stations_station_check
            CHECK (station IN ('".implode("','", self::STATIONS)."'))");

        // ── الردم ──
        $now = now();
        $rows = [];
        DB::table('assessments')->orderBy('id')->select('id')->chunk(500, function ($chunk) use (&$rows, $now) {
            $ids = collect($chunk)->pluck('id')->all();

            $scheduled = DB::table('schedules')
                ->whereIn('assessment_id', $ids)
                ->whereIn('activity', self::STATIONS)
                ->select('assessment_id', 'activity')->distinct()->get()
                ->groupBy('assessment_id')
                ->map(fn ($g) => $g->pluck('activity')->all());

            foreach ($ids as $id) {
                $stations = $scheduled[$id] ?? self::STATIONS;
                foreach (array_values($stations) as $i => $station) {
                    $rows[] = [
                        'assessment_id' => $id,
                        'station' => $station,
                        'sort_order' => $i,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        });

        foreach (array_chunk($rows, 1000) as $batch) {
            DB::table('assessment_stations')->insert($batch);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_stations');
    }
};
