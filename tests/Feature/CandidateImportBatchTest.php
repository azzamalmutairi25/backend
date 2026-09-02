<?php

namespace Tests\Feature;

use App\Jobs\ProcessCandidateImport;
use App\Models\Candidate;
use App\Models\ImportBatch;
use App\Services\CandidateImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// ════════════════════════════════════════════════════════════
//  الاستيراد الضخم: رفعةٌ تُجمَّع ثمّ تُعالَج في الخلفية
//
//  ما يُحرَس هنا ليس «هل تعمل» بل الأخطاء التي تظهر عند الحجم وحده:
//   ١) التكرار داخل الملفّ يجب أن يُكشف عبر الدفعات — خريطةٌ تموت مع كل
//      دفعة تسمح بهويةٍ واحدة أن تُنشئ مشاركين إن وردت في دفعتين.
//   ٢) الحمولة تُفرَّغ عند الانتهاء: هويات وأسماء وسِيَر كاملة لا تبقى
//      محفوظة بعد أن أدّت غرضها.
//   ٣) رفعةُ غيرك لا تُقرأ ولا يُضاف إليها.
// ════════════════════════════════════════════════════════════
class CandidateImportBatchTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function row(?string $nid = null): array
    {
        return [
            'nationalId' => $nid ?? $this->validNationalId(),
            'fullName' => 'مشارك دفعة',
            'sectorCode' => 'DW',
            'rankLabel' => 'الرابعة عشرة',
            'personnelCategory' => 'civilian',
            'gender' => 'ذكر',
            'technicalAreas' => ['القيادة'],
            'cv' => $this->validCvDoc(),
        ];
    }

    public function test_a_batch_is_assembled_across_calls_then_queued(): void
    {
        Queue::fake();
        $this->actingAsRole('SCHEDULER');

        $first = $this->postJson('/api/candidates/import/batch', [
            'rows' => [$this->row()], 'filename' => 'كشف.xlsx',
        ])->assertStatus(201)->json();

        $this->assertSame(1, $first['received']);
        $this->assertFalse($first['queued']);
        Queue::assertNothingPushed();

        $second = $this->postJson('/api/candidates/import/batch', [
            'batchId' => $first['batchId'], 'rows' => [$this->row()], 'final' => true,
        ])->assertStatus(201)->json();

        $this->assertSame(2, $second['received']);
        $this->assertTrue($second['queued']);
        Queue::assertPushed(ProcessCandidateImport::class);
    }

    public function test_processing_creates_the_candidates_and_reports_progress(): void
    {
        $user = $this->actingAsRole('SCHEDULER');
        $batch = ImportBatch::create([
            'user_id' => $user->id, 'status' => 'queued',
            'payload' => [$this->row(), $this->row()], 'total_rows' => 2,
        ]);

        (new ProcessCandidateImport($batch->id))->handle(app(CandidateImporter::class));

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->created_count);
        $this->assertSame(2, $batch->processed_rows);
        $this->assertSame(0, $batch->failed_count);
        $this->assertSame(2, Candidate::count());
    }

    // ── الفخّ الأوّل: التكرار يجب أن يُكشف عبر حدود الدفعة ──
    // الدفعة مئة صفّ، فهويةٌ في الصفّ الأوّل وأخرى مثلها في الصفّ ١٥٠ تقعان
    // في دفعتين مختلفتين — وخريطةٌ تموت مع كل دفعة تُنشئ لهما مشاركين
    public function test_a_duplicate_across_chunk_boundaries_is_caught(): void
    {
        $user = $this->actingAsRole('SCHEDULER');
        $nid = $this->validNationalId();

        $rows = [$this->row($nid)];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = $this->row();
        }
        $rows[] = $this->row($nid);   // الصفّ ١٢٢ — دفعةٌ ثانية

        $batch = ImportBatch::create([
            'user_id' => $user->id, 'status' => 'queued',
            'payload' => $rows, 'total_rows' => count($rows),
        ]);

        (new ProcessCandidateImport($batch->id))->handle(app(CandidateImporter::class));

        $batch->refresh();
        $this->assertSame(1, $batch->failed_count, 'المكرّر عبر الدفعات لم يُكشف');
        $this->assertSame(121, $batch->created_count);
        $this->assertSame(1, Candidate::where('national_id_hash', hash('sha256', $nid))->count());
        $this->assertStringContainsString('مكرّر', json_encode($batch->failures, JSON_UNESCAPED_UNICODE));
    }

    // ── الفخّ الثاني: الحمولة بيانات شخصية، لا تبقى بعد أداء غرضها ──
    public function test_the_payload_is_wiped_when_the_batch_finishes(): void
    {
        $user = $this->actingAsRole('SCHEDULER');
        $batch = ImportBatch::create([
            'user_id' => $user->id, 'status' => 'queued',
            'payload' => [$this->row()], 'total_rows' => 1,
        ]);

        (new ProcessCandidateImport($batch->id))->handle(app(CandidateImporter::class));

        $this->assertNull($batch->fresh()->payload);
    }

    public function test_a_batch_is_processed_once_only(): void
    {
        $user = $this->actingAsRole('SCHEDULER');
        $batch = ImportBatch::create([
            'user_id' => $user->id, 'status' => 'queued',
            'payload' => [$this->row()], 'total_rows' => 1,
        ]);
        $job = new ProcessCandidateImport($batch->id);

        $job->handle(app(CandidateImporter::class));
        $job->handle(app(CandidateImporter::class));   // إعادةٌ لا تُنشئ ثانية

        $this->assertSame(1, Candidate::count());
    }

    // ── رفعةُ غيرك ──
    public function test_another_users_batch_is_neither_read_nor_appended_to(): void
    {
        $owner = $this->actingAsRole('SCHEDULER');
        $batch = ImportBatch::create(['user_id' => $owner->id, 'status' => 'queued', 'payload' => []]);

        $this->actingAsRole('SCHEDULER');   // مستخدم آخر بالدور نفسه
        $this->getJson("/api/candidates/import/batch/{$batch->id}")->assertStatus(404);
        $this->postJson('/api/candidates/import/batch', [
            'batchId' => $batch->id, 'rows' => [$this->row()],
        ])->assertStatus(404);
    }

    public function test_importing_requires_create_permission(): void
    {
        $this->actingAsRole('MEASURE_SUPER');
        $this->postJson('/api/candidates/import/batch', ['rows' => [$this->row()]])->assertStatus(403);
    }

    // المستورَد يجيب عن «من أضافني؟» كما يجيب عنه المُدخَل يدوياً
    public function test_an_imported_candidate_records_who_added_it(): void
    {
        $user = $this->actingAsRole('SCHEDULER');
        $batch = ImportBatch::create([
            'user_id' => $user->id, 'status' => 'queued',
            'payload' => [$this->row()], 'total_rows' => 1,
        ]);

        (new ProcessCandidateImport($batch->id))->handle(app(CandidateImporter::class));

        $c = Candidate::first();
        $trail = $this->getJson("/api/candidates/{$c->id}")->assertOk()->json('candidate.trail');
        $entry = collect($trail)->firstWhere('title', 'أُضيف عبر الاستيراد الجماعي');

        $this->assertNotNull($entry, 'المستورَد بلا قيدٍ يقول من أضافه');
        $this->assertSame($user->full_name, $entry['actor']);
    }
}
