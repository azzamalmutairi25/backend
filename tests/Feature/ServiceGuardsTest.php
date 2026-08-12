<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// حرّاس حدود الخدمات: رفض المُدخَل غير الصالح قبل أن يصل القاعدة
class ServiceGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // ── NotificationService ──

    public function test_service_types_match_the_database_check_constraint(): void
    {
        $def = \DB::selectOne(
            "select pg_get_constraintdef(con.oid) def from pg_constraint con
             join pg_class rel on rel.oid = con.conrelid
             where con.contype = 'c' and rel.relname = 'notifications'"
        )->def;

        // لو أضاف أحدهم نوعاً في القاعدة ونسي الخدمة (أو العكس) — يسقط هنا
        foreach (NotificationService::TYPES as $t) {
            $this->assertStringContainsString("'{$t}'", $def, "النوع {$t} مفقود من قيد القاعدة");
        }
        preg_match_all("/'([a-z]+)'/", $def, $m);
        $this->assertEqualsCanonicalizing($m[1], NotificationService::TYPES, 'القائمتان متطابقتان');
    }

    public function test_unknown_notification_type_is_rejected_at_the_boundary(): void
    {
        $u = $this->actingAsRole('ASSESS_MANAGER');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("نوع إشعار غير معروف: 'not_a_type'");
        app(NotificationService::class)->notify($u->id, 'not_a_type', 'عنوان');
    }

    public function test_rejection_happens_before_any_write(): void
    {
        $u = $this->actingAsRole('ASSESS_MANAGER');

        try {
            app(NotificationService::class)->notify($u->id, 'bad', 'عنوان');
        } catch (\InvalidArgumentException) {
            // المقصد: لم تُلمس القاعدة، فالمعاملة المحيطة سليمة ويمكن الاستعلام بعدها
        }

        $this->assertSame(0, Notification::count(), 'لا كتابة');
        $this->assertDatabaseCount('notifications', 0); // استعلام ناجح = المعاملة غير مُجهَضة
    }

    public function test_every_valid_type_is_accepted(): void
    {
        $u = $this->actingAsRole('ASSESS_MANAGER');
        foreach (NotificationService::TYPES as $t) {
            app(NotificationService::class)->notify($u->id, $t, "عنوان {$t}");
        }
        $this->assertSame(count(NotificationService::TYPES), Notification::count());
    }

    // ── ScoringService ──

    public function test_unknown_tier_is_rejected_instead_of_silently_using_middle(): void
    {
        $this->actingAsRole('EVALUATOR');
        [, $a] = $this->makeCandidate(['status' => 'assessed']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("فئة قيادية غير معروفة: 'GARBAGE'");
        app(ScoringService::class)->computeGap($a, 'GARBAGE');
    }

    public function test_both_valid_tiers_are_accepted(): void
    {
        $this->actingAsRole('EVALUATOR');
        [, $a] = $this->makeCandidate(['status' => 'assessed']);

        foreach (['upper', 'middle'] as $tier) {
            $r = app(ScoringService::class)->computeGap($a, $tier);
            $this->assertSame($tier, $r['tier']);
        }
    }

    // المستدعي الوحيد يمرّر candidate->tier، والقاعدة تحصره في upper|middle
    // وnull يُعالَج بـ ?? 'middle' — فالحارس لا يمكن أن يُطلَق من المسار الحقيقي
    public function test_gap_endpoint_works_for_a_real_candidate_of_each_tier(): void
    {
        foreach (['upper', 'middle'] as $tier) {
            $this->actingAsRole('EVALUATOR');
            [$c, ] = $this->makeCandidate(['status' => 'assessed', 'tier' => $tier]);

            $this->actingAsRole('ASSESS_MANAGER'); // REPORT_CREATE
            $this->getJson("/api/reports/competency-gap?candidateId={$c->id}")
                ->assertOk()
                ->assertJsonPath('tier', $tier);
        }
    }
}
