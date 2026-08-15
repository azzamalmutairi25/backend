<?php

namespace Tests\Feature;

use App\Models\Competency;
use App\Models\Evaluation;
use App\Models\FinalReport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  ترقيم التقارير والتقييمات والمستخدمين — بالنمط نفسه
//
//  المشترك مُختبَر في CandidateListPagingTest. هنا ما يخصّ كل قائمة: اتجاهها
//  الافتراضي (قائمةٌ تعرض الأحدث أولاً لا تنقلب إلى الأقدم لأنّ الترقيم أُضيف)،
//  وفاصلها الثابت، وحصرها.
// ════════════════════════════════════════════════════════════
class ListPagingTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function reports(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            [$c, $a] = $this->makeCandidate(['status' => 'assessed', 'code' => sprintf('R-%03d', $i)]);
            $r = FinalReport::create([
                'candidate_id' => $c->id, 'assessment_id' => $a->id,
                'behavioral_fit' => 70 + $i, 'technical_fit' => 60 + $i,
                'recommendation' => 'جاهز', 'status' => 'draft', 'created_by' => null,
            ]);
            // بعد الإنشاء لا داخله: الطوابع تُكتب تلقائياً فتُهمَل القيمة المرسلة،
            // فتتساوى الصفوف في الثانية ويحسم الفاصلُ ترتيبَها لا التاريخ
            $r->forceFill(['created_at' => now()->subMinutes($n - $i)])->saveQuietly();
        }
    }

    // ── التقارير ──

    public function test_reports_still_come_newest_first_by_default(): void
    {
        $this->reports(6);
        $this->actingAsRole('ASSESS_MANAGER');

        $codes = collect($this->getJson('/api/reports')->assertOk()->json('reports'))
            ->pluck('participantCode');

        $this->assertSame('R-006', $codes->first(), 'الأحدث أولاً — انقلب الاتجاه بإضافة الترقيم');
        $this->assertSame('R-001', $codes->last());
    }

    public function test_reports_page_and_report_the_whole(): void
    {
        $this->reports(6);
        $this->actingAsRole('ASSESS_MANAGER');

        $res = $this->getJson('/api/reports?page=1&perPage=4')->assertOk();

        $this->assertCount(4, $res->json('reports'));
        $this->assertSame(6, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.lastPage'));
        $this->assertSame('desc', $res->json('meta.dir'));
    }

    public function test_reports_cover_every_row_exactly_once(): void
    {
        $this->reports(7);
        $this->actingAsRole('ASSESS_MANAGER');

        $seen = [];
        foreach ([1, 2, 3] as $p) {
            foreach ($this->getJson("/api/reports?page={$p}&perPage=3")->json('reports') as $r) {
                $seen[] = $r['id'];
            }
        }

        $this->assertCount(7, $seen);
        $this->assertCount(7, array_unique($seen), 'تقريرٌ ظهر في صفحتين');
    }

    public function test_reports_reject_an_unknown_sort(): void
    {
        $this->actingAsRole('ASSESS_MANAGER');
        $this->getJson('/api/reports?sort=created_by')->assertStatus(422);
    }

    // العدّ يحترم النطاق: المقيّم لا يرى إلا تقارير من قيّمهم هو
    public function test_reports_total_respects_the_scope(): void
    {
        $this->reports(4);
        $ev = $this->actingAsRole('EVALUATOR', 'DW');

        $res = $this->getJson('/api/reports?page=1&perPage=100')->assertOk();

        $this->assertSame(0, $res->json('meta.total'), 'العدد الكلي تجاوز حصر المقيّم');
        $this->assertCount(0, $res->json('reports'));
    }

    // ── التقييمات ──

    private function evaluationsFor(User $evaluator, int $n, string $classification = 'normal'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            [$c, $a] = $this->makeCandidate([
                'status' => 'scheduled', 'classification' => $classification,
                'code' => sprintf('E%s-%03d', $classification === 'normal' ? 'N' : 'S', $i),
            ]);
            $e = Evaluation::create([
                'candidate_id' => $c->id, 'assessment_id' => $a->id,
                'evaluator_id' => $evaluator->id, 'activity' => 'interview',
                'status' => 'draft',
            ]);
            $e->forceFill(['updated_at' => now()->subMinutes($n - $i)])->saveQuietly();
        }
    }

    public function test_evaluations_still_come_newest_first_by_default(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $this->evaluationsFor($ev, 5);

        $codes = collect($this->getJson('/api/evaluations')->assertOk()->json('evaluations'))
            ->pluck('candidateCode');

        // EN/ES رمزان مركّبان في هذا الاختبار (E + تصنيف)، لا بادئة قطاع
        $this->assertSame('EN-005', $codes->first(), 'الأحدث أولاً — انقلب الاتجاه');
    }

    // الحصر انتقل إلى SQL: كان يُقصّ بعد الجلب، فمع الترقيم كان يُنتج صفحةً
    // ناقصة وعدداً يحسب المحجوبين
    public function test_the_classification_filter_now_bounds_the_count_not_just_the_rows(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'DW');   // بلا view_classified
        $this->evaluationsFor($ev, 4, 'normal');
        $this->evaluationsFor($ev, 6, 'secret');

        $res = $this->getJson('/api/evaluations?page=1&perPage=100')->assertOk();

        $this->assertSame(4, $res->json('meta.total'), 'العدد الكلي حسب المصنّفين المحجوبين');
        $this->assertCount(4, $res->json('evaluations'));
    }

    public function test_an_evaluation_page_is_full_not_short(): void
    {
        $ev = $this->actingAsRole('EVALUATOR', 'DW');
        $this->evaluationsFor($ev, 3, 'normal');
        $this->evaluationsFor($ev, 5, 'secret');   // محجوبة

        // لولا الحصر في SQL لجُلبت ٣ ثم قُصّ منها فظهرت صفحةٌ أقصر من حجمها
        $res = $this->getJson('/api/evaluations?page=1&perPage=3')->assertOk();

        $this->assertCount(3, $res->json('evaluations'), 'الصفحة جاءت ناقصة');
        $this->assertSame(3, $res->json('meta.total'));
    }

    // ── المستخدمون ──

    public function test_users_are_still_ordered_by_full_name(): void
    {
        $this->actingAsRole('ADMIN');
        foreach (['ياسر', 'أحمد', 'سعد'] as $i => $name) {
            User::create([
                'username' => 'u_order_' . $i, 'full_name' => $name, 'password' => 'Kafaat@2026',
                'role_id' => Role::where('code', 'ASSESS_MANAGER')->value('id'),
                'is_active' => true, 'must_change_password' => false,
            ]);
        }

        $names = collect($this->getJson('/api/users')->assertOk()->json('users'))->pluck('fullName');

        $this->assertSame($names->sort()->values()->all(), $names->all(), 'الترتيب لم يعد بالاسم');
    }

    public function test_users_page_and_report_the_whole(): void
    {
        $this->actingAsRole('ADMIN');

        $res = $this->getJson('/api/users?page=1&perPage=2')->assertOk();

        $this->assertCount(2, $res->json('users'));
        $this->assertGreaterThan(2, $res->json('meta.total'));
        $this->assertSame(1, $res->json('meta.page'));
    }

    public function test_users_reject_an_unknown_sort(): void
    {
        $this->actingAsRole('ADMIN');
        $this->getJson('/api/users?sort=password')->assertStatus(422);
    }

    // ── مشترك: الترقيم إضافة لا استبدال ──

    public function test_none_of_the_three_paginates_unless_asked(): void
    {
        $this->reports(3);
        $this->actingAsRole('ADMIN');

        foreach ([['/api/reports', 'reports'], ['/api/evaluations', 'evaluations'], ['/api/users', 'users']] as [$path, $key]) {
            $res = $this->getJson($path)->assertOk();
            $this->assertNull($res->json('meta.perPage'), "{$path} رقّم بلا طلب");
            $this->assertSame($res->json('meta.total'), count($res->json($key)),
                "{$path} قصّ صفوفاً بلا طلب ترقيم");
        }
    }
}
