<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicGateTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function seededGate(): array
    {
        [$c, $a] = $this->makeCandidate(['status' => 'scheduled']);
        $token = Str::random(48);
        $a->update(['confirm_token' => $token]);
        return [$c, $a, $token];
    }

    private function accessTokenFor(string $token, string $nationalId): string
    {
        return $this->postJson("/api/public/assessment/{$token}/verify", ['nationalId' => $nationalId])
            ->json('accessToken');
    }

    public function test_wrong_national_id_is_rejected_with_attempts_left(): void
    {
        [$c, $a, $token] = $this->seededGate();

        $this->postJson("/api/public/assessment/{$token}/verify", ['nationalId' => '1111111111'])
            ->assertStatus(403)
            ->assertJsonStructure(['error', 'attemptsLeft']);
    }

    public function test_correct_national_id_returns_data_and_access_token(): void
    {
        [$c, $a, $token] = $this->seededGate();

        $res = $this->postJson("/api/public/assessment/{$token}/verify", ['nationalId' => $c->national_id]);
        $res->assertOk()
            ->assertJsonStructure(['assessment' => ['name', 'participantCode'], 'accessToken']);
    }

    public function test_confirm_requires_access_token_and_is_one_time(): void
    {
        [$c, $a, $token] = $this->seededGate();

        // no access token -> 401
        $this->postJson("/api/public/assessment/{$token}/confirm", [])->assertStatus(401);

        $at = $this->postJson("/api/public/assessment/{$token}/verify", ['nationalId' => $c->national_id])
            ->json('accessToken');

        $this->postJson("/api/public/assessment/{$token}/confirm", ['accessToken' => $at])
            ->assertOk()->assertJsonPath('alreadyConfirmed', false);

        // second confirm is idempotent
        $this->postJson("/api/public/assessment/{$token}/confirm", ['accessToken' => $at])
            ->assertOk()->assertJsonPath('alreadyConfirmed', true);
    }

    public function test_tampered_access_token_is_rejected(): void
    {
        [$c, $a, $token] = $this->seededGate();

        $this->postJson("/api/public/assessment/{$token}/confirm", ['accessToken' => 'not-a-valid-token'])
            ->assertStatus(401);
    }

    public function test_five_wrong_attempts_then_locked(): void
    {
        [$c, $a, $token] = $this->seededGate();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/public/assessment/{$token}/verify", ['nationalId' => '2222222222'])
                ->assertStatus(403);
        }
        // 6th attempt (even a correct id) is locked out
        $this->postJson("/api/public/assessment/{$token}/verify", ['nationalId' => $c->national_id])
            ->assertStatus(429);
    }

    public function test_invalid_token_is_indistinguishable_from_wrong_id(): void
    {
        // anti-enumeration: a bad link returns the same 403 shape as a valid link + wrong id
        $this->postJson('/api/public/assessment/thisisnotarealtoken1234567890/verify', ['nationalId' => '1000000008'])
            ->assertStatus(403);
    }

    // ── arrive(): تسجيل ذاتي عبر insertOrIgnore — ينشئ حضوراً ثمّ يكون خاملاً (idempotent) بلا 500 ──
    public function test_arrive_marks_today_session_and_is_idempotent(): void
    {
        [$c, $a, $token] = $this->seededGate();
        $sch = Schedule::create([
            'candidate_id' => $c->id, 'assessment_id' => $a->id,
            'schedule_date' => now()->toDateString(), 'schedule_time' => '09:00',
            'activity' => 'interview', 'location' => 'قاعة 1',
        ]);

        $at = $this->accessTokenFor($token, $c->national_id);

        $res = $this->postJson("/api/public/assessment/{$token}/arrive", ['accessToken' => $at])->assertOk();
        $this->assertSame(1, $res->json('markedSessions'));
        $att = Attendance::where('schedule_id', $sch->id)->first();
        $this->assertNotNull($att);
        $this->assertSame('present', $att->status);

        // إعادة الإرسال (نقرة مزدوجة): لا 500، لا حضور مكرّر، لا صفوف جديدة
        $res2 = $this->postJson("/api/public/assessment/{$token}/arrive", ['accessToken' => $at])->assertOk();
        $this->assertSame(0, $res2->json('markedSessions'));
        $this->assertSame(1, Attendance::where('schedule_id', $sch->id)->count());
    }

    // ── arrive() يؤكّد ضمناً ويسجّل الوصول على الدورة ──
    public function test_arrive_sets_arrived_and_confirmed(): void
    {
        [$c, $a, $token] = $this->seededGate();
        $at = $this->accessTokenFor($token, $c->national_id);

        $this->postJson("/api/public/assessment/{$token}/arrive", ['accessToken' => $at])->assertOk();

        $fresh = $a->fresh();
        $this->assertNotNull($fresh->arrived_at);
        $this->assertNotNull($fresh->confirmed_at); // الوصول يؤكّد ضمناً
    }
}
