<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
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

    private function sector(string $code = 'DW'): Sector
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

        // الصيغة: بادئة + تسلسل رباعي + شهر + سنتان (DW0007Aug26)
        $numbers = array_map(fn ($c) => (int) substr($c, 2, 4), $codes);
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers, 'الترقيم غير تصاعدي');
    }

    // ═══ العدّاد ═══

    public function test_the_counter_is_seeded_from_codes_that_predate_it(): void
    {
        // رمز موجود قبل العدّاد — المهاجرة بذرت العدّاد من الجدولين
        $this->makeCandidate(['sectorCode' => 'DW', 'code' => 'DW-042']);

        // نُعيد بذر العدّاد كما تفعل الهجرة (البذر يقع مرّة واحدة عند الترقية)
        DB::table('participant_code_counters')->where('prefix', 'DW')->delete();
        DB::table('participant_code_counters')->insert([
            'prefix' => 'DW', 'last_number' => 42, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $expected = 'DW0043'.now()->format('My');
        $this->assertSame($expected, Assessment::generateParticipantCode($this->sector()));
    }

    public function test_the_counter_advances_by_exactly_one_per_call(): void
    {
        $sector = $this->sector();
        Assessment::generateParticipantCode($sector);

        $before = $this->counter('DW');
        Assessment::generateParticipantCode($sector);

        $this->assertSame($before + 1, $this->counter('DW'));
    }

    public function test_each_sector_keeps_its_own_series(): void
    {
        $ed = Assessment::generateParticipantCode($this->sector('DW'));
        $ho = Assessment::generateParticipantCode($this->sector('PR'));

        $this->assertStringStartsWith('DW0001', $ed);
        $this->assertStringStartsWith('PR0001', $ho);
        // قطاعٌ لا يُحرّك عدّاد قطاعٍ آخر — التنافس محصور بالبادئة
        $this->assertSame(1, $this->counter('PR'));
    }

    // رمزٌ سابقٌ للعدّاد بأرقام تتجاوز ما بُذر به (استيراد يدوي مثلاً)
    public function test_a_code_that_already_exists_is_skipped_not_collided_with(): void
    {
        $sector = $this->sector();
        // العدّاد عند صفر، لكن أوّل رمزٍ سيولّده محجوزٌ مسبقاً
        $taken = 'DW0001'.now()->format('My');
        $this->makeCandidate(['sectorCode' => 'DW', 'code' => $taken]);

        $code = Assessment::generateParticipantCode($sector);

        $this->assertNotSame($taken, $code, 'أُرجِع رمز محجوز');
        $this->assertFalse(
            Candidate::where('participant_code', $code)->exists()
            || Assessment::where('participant_code', $code)->exists()
        );
    }

    // ═══ التدفّق الكامل ═══

    // الرمز لم يعد يُصدَر عند الترشيح: من دخل القاعدة ولم تُجدوَل جلساته بعد
    // يبقى بلا رمز، ويُعرَف باسمه أو هويته. والعدّاد لا يُستهلَك لأجله.
    public function test_nomination_issues_no_code_and_does_not_touch_the_counter(): void
    {
        $this->actingAsRole('EXTERNAL_ADD');
        $sectorId = $this->sector()->id;
        $required = $this->candidateRequired();

        $before = $this->counter('DW');

        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/candidates', array_replace($required, [
                'nationalId' => $this->validNationalId(),
                'fullName' => "مشارك {$i}",
                'sectorId' => $sectorId,
                'personnelCategory' => 'military',
                'rankLabel' => 'عميد',
            ]))->assertStatus(201)->assertJsonPath('participantCode', null);
        }

        $this->assertSame(12, Candidate::whereNull('participant_code')->count());
        $this->assertSame($before, $this->counter('DW'), 'الترشيح لا يستهلك من عدّاد القطاع');
    }

    // ولا الاستيراد: كشفٌ من عشرة آلاف صفّ كان يستهلك عشرة آلاف رقم
    public function test_the_import_path_issues_no_codes_either(): void
    {
        $this->actingAsRole('SCHEDULER');
        $before = $this->counter('DW');

        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = $this->importRow([
                'nationalId' => $this->validNationalId(), 'fullName' => "مستورد {$i}",
                'mobile' => '', 'email' => '', 'sectorCode' => 'DW',
                'personnelCategory' => 'civilian', 'rankLabel' => 'الرابعة عشرة',
            ]);
        }

        $this->postJson('/api/candidates/import', ['rows' => $rows])->assertOk()
            ->assertJsonPath('imported', 5);

        $this->assertSame(5, Candidate::whereNull('participant_code')->count());
        $this->assertSame($before, $this->counter('DW'));
    }

    // ═══ الإصدار عند اعتماد الفترة ═══

    public function test_approving_a_period_issues_codes_for_its_participants(): void
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled', 'code' => null]);
        $this->assertNull($c->participant_code);

        $period = SchedulingPeriod::create([
            'name' => 'فترة '.uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'pending_center',
        ]);
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'period_id' => $period->id,
            'activity' => 'interview', 'schedule_date' => now()->addDay()->toDateString(),
            'schedule_time' => '09:00',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")
            ->assertOk()->assertJsonPath('codesIssued', 1);

        $code = $c->fresh()->participant_code;
        $this->assertNotNull($code, 'الاعتماد يُصدر الرمز');
        $this->assertStringStartsWith('DW', $code);
        // والرمز نفسه على الدورة — سجلٌّ تاريخي لا يُعاد كتابته
        $this->assertSame($code, $a->fresh()->participant_code);
    }

    // الشهر والسنة من تاريخ إضافة المشارك لا من يوم الاعتماد: الرمز يقول
    // متى دخل صاحبه المنصّة
    public function test_the_stamp_follows_the_add_date_not_the_approval_date(): void
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled', 'code' => null]);
        $c->forceFill(['created_at' => now()->subMonths(3)])->save();

        $period = SchedulingPeriod::create([
            'name' => 'فترة '.uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'pending_center',
        ]);
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'period_id' => $period->id,
            'activity' => 'interview', 'schedule_date' => now()->addDay()->toDateString(),
            'schedule_time' => '09:00',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertOk();

        $this->assertStringEndsWith(now()->subMonths(3)->format('My'), $c->fresh()->participant_code);
    }

    // من حمل رمزاً من قبل لا يُمسّ: إعادةُ اعتمادٍ لا تغيّر رمزاً طُبع على تصريح
    public function test_an_existing_code_is_never_reissued(): void
    {
        [$c, $a] = $this->makeCandidate(['sectorCode' => 'DW', 'status' => 'scheduled', 'code' => 'DW-042']);

        $period = SchedulingPeriod::create([
            'name' => 'فترة '.uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'pending_center',
        ]);
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'period_id' => $period->id,
            'activity' => 'interview', 'schedule_date' => now()->addDay()->toDateString(),
            'schedule_time' => '09:00',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")
            ->assertOk()->assertJsonPath('codesIssued', 0);

        $this->assertSame('DW-042', $c->fresh()->participant_code, 'الرمز القديم يبقى بصيغته');
    }

    // ═══ التكلفة ═══

    // العيب الثاني في الشيفرة القديمة: جلب كل رموز القطاع في كل إدراج،
    // فالكلفة تنمو مع عدد المشاركين حتى تصير كل إضافةٍ مسحاً للجدول.
    //
    // عدّ الاستعلامات لا يكشف هذا: الشيفرة القديمة تُصدر استعلاماً واحداً
    // أيضاً — لكنه يقرأ كل الصفوف. فنفحص شكل الاستعلام لا عدده: لا يجوز
    // أن يمسح التوليدُ عمودَ الرموز بـLIKE.
    public function test_generation_never_scans_the_participant_code_column(): void
    {
        $sector = $this->sector();
        for ($i = 0; $i < 30; $i++) {
            $this->makeCandidate(['sectorCode' => 'DW', 'code' => sprintf('DW-%03d', 500 + $i)]);
        }

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        Assessment::generateParticipantCode($sector);

        $scans = array_values(array_filter($queries, fn ($sql) => stripos($sql, 'participant_code') !== false
            && stripos($sql, 'like') !== false));

        $this->assertSame([], $scans,
            "التوليد ما زال يمسح عمود الرموز — الكلفة تنمو مع البيانات:\n".implode("\n", $scans));

        // ويقرأ من جدول العدّاد فعلاً — لا مسار جانبي صامت
        $this->assertNotEmpty(array_filter($queries,
            fn ($sql) => stripos($sql, 'participant_code_counters') !== false));
    }
}
