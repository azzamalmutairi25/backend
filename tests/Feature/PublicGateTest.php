<?php

namespace Tests\Feature;

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
}
