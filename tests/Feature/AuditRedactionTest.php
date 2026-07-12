<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function auditFor(int $candidateId): void
    {
        AuditLog::create([
            'user_id' => null,
            'action' => 'RECLASSIFY_CANDIDATE',
            'entity_type' => 'candidate',
            'entity_id' => (string) $candidateId,
            'details' => ['code' => 'SECRET-001', 'from' => 'normal', 'to' => 'top_secret'],
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    private function entryFor(array $entries, int $candidateId): ?array
    {
        foreach ($entries as $e) {
            if ($e['entityType'] === 'candidate' && (string) $e['entityId'] === (string) $candidateId) return $e;
        }
        return null;
    }

    public function test_system_log_redacts_classified_candidate_for_uncleared_viewer(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'secret']);
        $this->auditFor($c->id);
        $this->actingAsRole('CENTER_MANAGER'); // AUDIT_VIEW, no VIEW_CLASSIFIED

        $entries = $this->getJson('/api/audit/log')->assertOk()->json('entries');
        // classified candidate's entry must be redacted (id + details hidden)
        $entry = $this->entryFor($entries, $c->id);
        $this->assertTrue($entry === null || $entry['redacted'] === true && $entry['details'] === null);
    }

    public function test_system_log_redacts_deleted_candidate_fail_closed(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'secret']);
        $cid = $c->id;
        $this->auditFor($cid);
        $c->delete(); // hard delete — row gone, but the audit log remains

        $this->actingAsRole('CENTER_MANAGER');
        $entries = $this->getJson('/api/audit/log')->assertOk()->json('entries');
        $entry = $this->entryFor($entries, $cid);
        // a deleted candidate can't be re-checked -> must be treated as classified (redacted)
        $this->assertTrue($entry === null || ($entry['redacted'] === true && $entry['details'] === null));
    }

    public function test_candidate_history_is_404_for_classified_without_clearance(): void
    {
        [$c] = $this->makeCandidate(['classification' => 'secret']);
        $this->actingAsRole('CENTER_MANAGER'); // CANDIDATE_VIEW, no VIEW_CLASSIFIED

        $this->getJson("/api/candidates/{$c->id}/history")->assertStatus(404);
    }

    public function test_candidate_history_is_404_for_missing_candidate_fail_closed(): void
    {
        $this->actingAsRole('CENTER_MANAGER');
        $this->getJson('/api/candidates/999999/history')->assertStatus(404);
    }
}
