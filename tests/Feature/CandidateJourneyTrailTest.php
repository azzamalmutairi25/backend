<?php

namespace Tests\Feature;

use App\Models\MeasurementResult;
use App\Models\Schedule;
use App\Models\SchedulingPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  سير الأحداث في ملفّ المشارك — ما كان يُقيَّد ولا يُقرأ.
//
//  أحداثٌ كثيرة كانت مسجَّلةً في القاعدة منذ بُنيت مساراتُها، والقائمة
//  البيضاء وحدها تحجبها: حضورُ المركز، وطباعةُ البطاقة، وتغيّرُ الحالة
//  الوظيفية. وأثرٌ يُكتب ولا يُقرأ كأنه لم يُكتب.
// ════════════════════════════════════════════════════════════
class CandidateJourneyTrailTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAf'
        .'FcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function titles(int $candidateId): array
    {
        $this->actingAsRole('ADMIN');

        return collect($this->getJson("/api/candidates/{$candidateId}/journey")
            ->assertOk()->json('journey'))->pluck('title')->all();
    }

    // ═══ أحداث الاستقبال ═══

    public function test_the_reception_trail_reaches_the_timeline(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->giveCv($c);

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        $this->postJson("/api/reception/visits/{$visitId}/sign", [
            'signature' => self::PNG, 'attested' => true,
        ])->assertOk();
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();
        $this->postJson("/api/reception/visits/{$visitId}/badge-printed")->assertOk();

        $titles = $this->titles($c->id);

        $this->assertContains('حضر إلى المركز', $titles);
        $this->assertContains('اعتمد الاستقبال سيرته', $titles);
        $this->assertContains('طُبعت بطاقته', $titles);
    }

    // تصحيح السيرة يقول ماذا تغيّر — لا «عُدّلت» وحدها
    public function test_a_reception_correction_names_the_changed_fields(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->giveCv($c, ['currentPosition' => 'مدير إدارة']);

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['currentPosition' => 'وكيل مساعد'],
        ])->assertOk();

        $this->actingAsRole('ADMIN');
        $ev = collect($this->getJson("/api/candidates/{$c->id}/journey")->json('journey'))
            ->firstWhere('title', 'صُحّحت سيرته عند الاستقبال');

        $this->assertNotNull($ev);
        $this->assertSame('المنصب الحالي', $ev['meta']);
    }

    public function test_cv_revisions_appear_with_their_version_and_fields(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->giveCv($c, ['currentPosition' => 'مدير إدارة']);

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        $this->putJson("/api/reception/visits/{$visitId}/cv", [
            'cv' => ['totalYearsExperience' => 19],
        ])->assertOk();

        $this->actingAsRole('ADMIN');
        $ev = collect($this->getJson("/api/candidates/{$c->id}/journey")->json('journey'))
            ->firstWhere('type', 'cv_revision');

        $this->assertSame('إصدار السيرة رقم 2', $ev['title']);
        $this->assertSame('إجمالي سنوات الخبرة', $ev['meta']);
    }

    // ═══ صدور الرمز ═══

    public function test_the_code_issuance_is_recorded_by_its_owners_name(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled', 'code' => null]);
        $period = SchedulingPeriod::create([
            'name' => 'موجة الرمز',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'pending_center',
        ]);
        Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'period_id' => $period->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '10:00',
            'activity' => 'interview',
        ]);

        $this->actingAsRole('SCHEDULER');
        $this->postJson("/api/scheduling-periods/{$period->id}/approve")->assertOk();

        $this->actingAsRole('ADMIN');
        $ev = collect($this->getJson("/api/candidates/{$c->id}/journey")->json('journey'))
            ->firstWhere('title', 'صدر رمز المشارك');

        $this->assertNotNull($ev, 'كان يُعَدّ في قيدٍ واحد للموجة — فلا يُعرف متى صدر رمزُ فلان');
        $this->assertSame('موجة الرمز', $ev['meta'], 'أيُّ موجةٍ أصدرته');
        $this->assertNotNull($ev['cycle'], 'والرمز نفسه');
    }

    // ═══ المحطّة الثالثة ═══

    // أدوات القياس لا تمرّ بالرصد، فبلا حدثها يبقى في الخطّ ثقبٌ لا يُفسَّر
    public function test_the_measurement_station_leaves_a_mark(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        MeasurementResult::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'personality_score' => 75,
        ]);

        $this->assertContains('أتمّ أدوات القياس', $this->titles($c->id));
    }

    // ═══ الحالة الوظيفية ═══

    public function test_an_employment_change_shows(): void
    {
        [$c] = $this->makeCandidate();

        $this->actingAsRole('SCHEDULER');
        $this->patchJson("/api/candidates/{$c->id}/employment", ['employmentStatus' => 'retired'])
            ->assertOk();

        $this->actingAsRole('ADMIN');
        $ev = collect($this->getJson("/api/candidates/{$c->id}/journey")->json('journey'))
            ->firstWhere('title', 'تغيّرت الحالة الوظيفية');

        $this->assertNotNull($ev);
        // القيد يخزّن القيمة خاماً — والشاشة عربية، فتُترجَم عند العرض
        $this->assertSame('متقاعد', $ev['meta']);
    }

    // ═══ الحمولة ═══

    public function test_the_payload_carries_the_station_counter_and_the_centre_visit(): void
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $this->giveCv($c);
        MeasurementResult::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id, 'analytical_score' => 60,
        ]);

        $this->actingAsRole('RECEPTIONIST');
        $visitId = $this->postJson('/api/reception/arrive', ['assessmentId' => $a->id])
            ->assertStatus(201)->json('visitId');
        $this->postJson("/api/reception/visits/{$visitId}/cv/approve")->assertOk();

        $this->actingAsRole('ADMIN');
        $res = $this->getJson("/api/candidates/{$c->id}/journey")->assertOk();

        $this->assertSame(3, $res->json('stations.chosen'));
        $this->assertSame(1, $res->json('stations.done'));
        $this->assertSame(['المقابلة الشخصية', 'حلقة النقاش'], $res->json('stations.missing'));
        $this->assertFalse($res->json('stations.complete'));

        $this->assertSame(now()->toDateString(), $res->json('centreVisit.date'));
        $this->assertTrue($res->json('centreVisit.cvApproved'));
        $this->assertFalse($res->json('centreVisit.badgePrinted'));
        $this->assertSame('على رأس العمل', $res->json('candidate.employmentStatusLabel'));
    }

    // ═══ الحارس ═══

    // الاستقبال لا يفتح الخطّ الزمني: يحمل أحداث التقرير، ومسارُ التقرير
    // محجوبٌ عنه أبداً — وتقدُّمُه على المحطّات يصله من كشف الاستقبال نفسه
    public function test_reception_does_not_open_the_timeline(): void
    {
        [$c] = $this->makeCandidate();

        $this->actingAsRole('RECEPTIONIST');
        $this->getJson("/api/candidates/{$c->id}/journey")->assertStatus(403);
    }
}
