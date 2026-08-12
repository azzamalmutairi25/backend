<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Sector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  ترقيم قائمة المرشحين وفرزها على الخادم
//
//  الترقيم إضافةٌ لا استبدال: بلا `page` تبقى القائمة كاملةً كما كانت، فلا
//  ينكسر عميلٌ قائم بصمت — يرى خمسين صفّاً مكان ألف ويظنّها كلّ ما في المركز.
//  ولهذا يُفحَص الوجهان: القديم كما هو، والجديد يعمل.
// ════════════════════════════════════════════════════════════
class CandidateListPagingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** ١٢ مرشّحاً برموز مرتّبة وقطاعات ورتب مختلفة */
    private function makeMany(int $n = 12): void
    {
        $ed = Sector::where('code', 'ED')->value('id');
        $da = Sector::where('code', 'DA')->value('id');

        for ($i = 1; $i <= $n; $i++) {
            $c = new Candidate();
            $c->national_id = $this->validNationalId();
            $c->full_name = "مرشح {$i}";
            $c->mobile = '0501112223';
            $c->sector_id = $i % 2 === 0 ? $da : $ed;
            $c->rank_label = $i % 3 === 0 ? 'وكيل وزارة' : 'مدير عام';
            $c->tier = $i % 2 === 0 ? 'upper' : 'middle';
            $c->status = 'approved';
            $c->classification = 'normal';
            $c->participant_code = sprintf('P-%03d', $i);
            $c->save();
        }
    }

    // ── التوافق مع ما كان ──

    public function test_without_paging_the_whole_list_comes_back_as_before(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $res = $this->getJson('/api/candidates')->assertOk();

        $this->assertCount(12, $res->json('candidates'));
        $this->assertSame(12, $res->json('meta.total'));
        $this->assertNull($res->json('meta.perPage'), 'بلا ترقيم لا حجم صفحة');
        $this->assertFalse($res->json('meta.truncated'));
    }

    public function test_the_default_order_is_still_the_participant_code(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $codes = collect($this->getJson('/api/candidates')->json('candidates'))->pluck('participantCode');

        $this->assertSame($codes->sort()->values()->all(), $codes->all());
    }

    // ── الترقيم ──

    public function test_a_page_returns_only_its_rows_and_reports_the_whole(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $res = $this->getJson('/api/candidates?page=1&perPage=5')->assertOk();

        $this->assertCount(5, $res->json('candidates'));
        $this->assertSame(12, $res->json('meta.total'), 'العدد الكلي لا عدد الصفحة');
        $this->assertSame(3, $res->json('meta.lastPage'));
        $this->assertSame('P-001', $res->json('candidates.0.participantCode'));

        $last = $this->getJson('/api/candidates?page=3&perPage=5')->assertOk();
        $this->assertCount(2, $last->json('candidates'));
        $this->assertSame('P-012', $last->json('candidates.1.participantCode'));
    }

    // كل صفّ يظهر مرّةً واحدة عبر الصفحات — لا تكرار ولا سقوط
    public function test_paging_covers_every_row_exactly_once(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $seen = [];
        for ($p = 1; $p <= 3; $p++) {
            foreach ($this->getJson("/api/candidates?page={$p}&perPage=5")->json('candidates') as $row) {
                $seen[] = $row['participantCode'];
            }
        }

        $this->assertCount(12, $seen);
        $this->assertCount(12, array_unique($seen), 'صفٌّ ظهر في صفحتين');
    }

    // صفحةٌ تجاوزت الآخر تُشدّ إلى الأخيرة — لا نتيجة فارغة تُوهم بأنّ لا شيء
    public function test_a_page_beyond_the_end_is_clamped_not_emptied(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $res = $this->getJson('/api/candidates?page=99&perPage=5')->assertOk();

        $this->assertSame(3, $res->json('meta.page'));
        $this->assertCount(2, $res->json('candidates'));
    }

    public function test_the_filter_is_applied_before_the_page_not_after(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $res = $this->getJson('/api/candidates?tier=upper&page=1&perPage=100')->assertOk();

        $this->assertSame(6, $res->json('meta.total'), 'العدد يعكس الفلتر');
        foreach ($res->json('candidates') as $row) {
            $this->assertSame('upper', $row['tier']);
        }
    }

    // ── الفرز ──

    public function test_sorting_by_code_descending(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $res = $this->getJson('/api/candidates?sort=code&dir=desc')->assertOk();

        $this->assertSame('P-012', $res->json('candidates.0.participantCode'));
        $this->assertSame('desc', $res->json('meta.dir'));
    }

    public function test_sorting_by_sector_uses_the_arabic_name_not_the_id(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $names = collect($this->getJson('/api/candidates?sort=sector')->json('candidates'))
            ->pluck('sectorName');

        $sorted = $names->sort(fn ($a, $b) => strcmp($a, $b))->values();
        $this->assertSame($sorted->all(), $names->all(), 'الفرز بالمعرّف لا بالاسم');
    }

    // فرزٌ على عمودٍ متكرّر القيم لا يُنتج ترتيباً عشوائياً بين الصفحات
    public function test_a_tie_breaker_keeps_pages_stable(): void
    {
        $this->makeMany();
        $this->actingAsRole('SCHEDULER');

        $first = collect($this->getJson('/api/candidates?sort=tier&page=1&perPage=6')->json('candidates'))
            ->pluck('participantCode');
        $second = collect($this->getJson('/api/candidates?sort=tier&page=2&perPage=6')->json('candidates'))
            ->pluck('participantCode');

        $this->assertCount(12, $first->merge($second)->unique(),
            'صفٌّ تكرّر أو سقط — الفرز بلا فاصلٍ ثابت');
    }

    // ── الحدود ──

    public function test_an_unknown_sort_column_is_rejected_not_passed_through(): void
    {
        $this->actingAsRole('SCHEDULER');

        $this->getJson('/api/candidates?sort=national_id')->assertStatus(422);
        $this->getJson('/api/candidates?sort=id;drop')->assertStatus(422);
        $this->getJson('/api/candidates?dir=sideways')->assertStatus(422);
    }

    public function test_per_page_has_a_ceiling(): void
    {
        $this->actingAsRole('SCHEDULER');

        $this->getJson('/api/candidates?perPage=10000')->assertStatus(422);
        $this->getJson('/api/candidates?perPage=0')->assertStatus(422);
    }

    // الحصر لا يُخترَق بالترقيم — الفلتر يُطبَّق قبل العدّ وقبل الصفحة
    public function test_the_scope_still_binds_a_sector_bound_user(): void
    {
        $this->makeMany();
        $this->actingAsRole('EVALUATOR', 'ED');

        $res = $this->getJson('/api/candidates?page=1&perPage=100')->assertOk();

        $this->assertSame(6, $res->json('meta.total'), 'العدد الكلي يحترم حدّ القطاع');
        foreach ($res->json('candidates') as $row) {
            $this->assertSame('التعليم', $row['sectorName']);
        }
    }
}
