<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  توليد رمز المشارك — الترقيم في القاعدة لا في ذاكرة PHP.
//
//  العطل الذي تحرسه هذه الاختبارات ظهر في قياس الحمل: ٣٦٪ من الترشيحات
//  فشلت بـ500 تحت ثمانية كتّاب متزامنين، لأن المولّد كان يقرأ أعلى رقم ثم
//  يُضيف واحداً — فطلبان متزامنان يقرآن القيمة نفسها ويولّدان الرمز نفسه.
//
//  المحكّ الحاسم أدناه (`test_two_consecutive_calls_never_collide`) يكشف
//  السباق بلا تزامن حقيقي: النداء مرّتين متتاليتين دون إدراج بينهما كان
//  يُرجِع الرمزَ نفسه — وهو السباق مُجسَّداً في خطوتين تسلسليتين.
// ════════════════════════════════════════════════════════════
class ParticipantCodeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function sector(string $code = 'ED'): Sector
    {
        return Sector::where('code', $code)->firstOrFail();
    }

    private function counter(string $prefix): ?int
    {
        $v = DB::table('participant_code_counters')->where('prefix', $prefix)->value('last_number');
        return $v === null ? null : (int) $v;
    }

    // ═══ صلب المسألة ═══

    // بلا إدراجٍ بين النداءين. المولّد القديم يقرأ الجدول فيرى الحدّ الأقصى
    // نفسه مرّتين ويرجع الرمز نفسه — وهو بالضبط ما يحدث لطلبين متزامنين.
    public function test_two_consecutive_calls_never_collide(): void
    {
        $sector = $this->sector();

        $first = Assessment::generateParticipantCode($sector);
        $second = Assessment::generateParticipantCode($sector);

        $this->assertNotSame($first, $second,
            'نداءان متتاليان أرجعا الرمز نفسه — هذا هو السباق الذي أسقط ٣٦٪ من الترشيحات');
    }

    public function test_a_long_run_of_calls_is_strictly_unique_and_ascending(): void
    {
        $sector = $this->sector();
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $codes[] = Assessment::generateParticipantCode($sector);
        }

        $this->assertCount(200, array_unique($codes), 'تكرّر رمز خلال ٢٠٠ توليد');

        $numbers = array_map(fn ($c) => (int) substr($c, strrpos($c, '-') + 1), $codes);
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers, 'الترقيم غير تصاعدي');
    }

    // ═══ العدّاد ═══

    public function test_the_counter_is_seeded_from_codes_that_predate_it(): void
    {
        // رمز موجود قبل العدّاد — المهاجرة بذرت العدّاد من الجدولين
        $this->makeCandidate(['sectorCode' => 'ED', 'code' => 'ED-042']);

        // نُعيد بذر العدّاد كما تفعل الهجرة (البذر يقع مرّة واحدة عند الترقية)
        DB::table('participant_code_counters')->where('prefix', 'ED')->delete();
        DB::table('participant_code_counters')->insert([
            'prefix' => 'ED', 'last_number' => 42, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('ED-043', Assessment::generateParticipantCode($this->sector()));
    }

    public function test_the_counter_advances_by_exactly_one_per_call(): void
    {
        $sector = $this->sector();
        Assessment::generateParticipantCode($sector);

        $before = $this->counter('ED');
        Assessment::generateParticipantCode($sector);

        $this->assertSame($before + 1, $this->counter('ED'));
    }

    public function test_each_sector_keeps_its_own_series(): void
    {
        $ed = Assessment::generateParticipantCode($this->sector('ED'));
        $ho = Assessment::generateParticipantCode($this->sector('HO'));

        $this->assertStringStartsWith('ED-', $ed);
        $this->assertStringStartsWith('HO-', $ho);
        // قطاعٌ لا يُحرّك عدّاد قطاعٍ آخر — التنافس محصور بالبادئة
        $this->assertSame(1, $this->counter('HO'));
    }

    // رمزٌ سابقٌ للعدّاد بأرقام تتجاوز ما بُذر به (استيراد يدوي مثلاً)
    public function test_a_code_that_already_exists_is_skipped_not_collided_with(): void
    {
        $sector = $this->sector();
        // العدّاد عند صفر، لكن الرمز ED-001 محجوز مسبقاً
        $this->makeCandidate(['sectorCode' => 'ED', 'code' => 'ED-001']);

        $code = Assessment::generateParticipantCode($sector);

        $this->assertNotSame('ED-001', $code, 'أُرجِع رمز محجوز');
        $this->assertFalse(
            Candidate::where('participant_code', $code)->exists()
            || Assessment::where('participant_code', $code)->exists()
        );
    }

    // ═══ التدفّق الكامل ═══

    // ما كان يفشل فعلاً: عدّة ترشيحات متلاحقة في القطاع نفسه
    public function test_repeated_nominations_in_one_sector_all_succeed(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');
        $sectorId = $this->sector()->id;

        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $res = $this->postJson('/api/candidates', [
                'nationalId' => $this->validNationalId(),
                'fullName' => "مرشح {$i}",
                'sectorId' => $sectorId,
                'rankLabel' => 'عميد',
            ])->assertStatus(201);
            $codes[] = $res->json('participantCode');
        }

        $this->assertCount(12, array_unique($codes), 'تكرّر رمز بين الترشيحات');
        $this->assertSame(12, Candidate::whereIn('participant_code', $codes)->count());
    }

    public function test_the_import_path_uses_the_same_series(): void
    {
        $this->actingAsRole('SCHEDULER');

        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'nationalId' => $this->validNationalId(), 'fullName' => "مستورد {$i}",
                'mobile' => '', 'email' => '', 'sectorCode' => 'ED', 'rankLabel' => 'مدير عام',
            ];
        }

        $this->postJson('/api/candidates/import', ['rows' => $rows])->assertOk()
            ->assertJsonPath('imported', 5);

        // خمسة رموز فريدة، والعدّاد يعكسها — لا مسار جانبي يتجاوزه
        $this->assertSame(5, Candidate::where('participant_code', 'like', 'ED-%')->distinct('participant_code')->count());
        $this->assertGreaterThanOrEqual(5, $this->counter('ED'));
    }

    // ═══ التكلفة ═══

    // العيب الثاني في الشيفرة القديمة: جلب كل رموز القطاع في كل إدراج،
    // فالكلفة تنمو مع عدد المرشحين حتى تصير كل إضافةٍ مسحاً للجدول.
    //
    // عدّ الاستعلامات لا يكشف هذا: الشيفرة القديمة تُصدر استعلاماً واحداً
    // أيضاً — لكنه يقرأ كل الصفوف. فنفحص شكل الاستعلام لا عدده: لا يجوز
    // أن يمسح التوليدُ عمودَ الرموز بـLIKE.
    public function test_generation_never_scans_the_participant_code_column(): void
    {
        $sector = $this->sector();
        for ($i = 0; $i < 30; $i++) {
            $this->makeCandidate(['sectorCode' => 'ED', 'code' => sprintf('ED-%03d', 500 + $i)]);
        }

        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        Assessment::generateParticipantCode($sector);

        $scans = array_values(array_filter($queries, fn ($sql) => stripos($sql, 'participant_code') !== false
            && stripos($sql, 'like') !== false));

        $this->assertSame([], $scans,
            "التوليد ما زال يمسح عمود الرموز — الكلفة تنمو مع البيانات:\n" . implode("\n", $scans));

        // ويقرأ من جدول العدّاد فعلاً — لا مسار جانبي صامت
        $this->assertNotEmpty(array_filter($queries,
            fn ($sql) => stripos($sql, 'participant_code_counters') !== false));
    }
}
