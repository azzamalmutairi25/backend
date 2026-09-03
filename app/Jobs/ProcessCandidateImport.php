<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Services\CandidateImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

// ════════════════════════════════════════════════════════════
//  معالجة رفعة استيراد في الخلفية.
//
//  عشرة آلاف صفٍّ لا تُعالَج في نداء HTTP: كل صفٍّ يفتح معاملة ويُشفّر سيرة،
//  فينقضي وقت الخادم أو ينقطع المتصفّح — وقد أُنشئ نصف الملفّ ولا أحد يعرف كم
//  ولا أين وقف. هنا تُعالَج على دفعات، ويُحدَّث العدّاد بعد كل دفعة فتقرأ
//  الشاشة تقدّماً حقيقياً لا شريطاً يتحرّك بالتخمين.
//
//  ── مستقلّة عن السائق ──
//  لا شيء هنا يخصّ Redis: تعمل على `database` كما تعمل على `redis` بـHorizon.
//  التبديل سطرٌ في `.env`، والشيفرة لا تتغيّر.
//
//  ── خريطة التكرار تعبر الدفعات ──
//  «مكرّر داخل الملفّ» يُكشف بخريطةٍ تُبنى وهي تقرأ. لو ماتت مع كل دفعة لمرّت
//  هويةٌ وردت في الدفعة الأولى ثانيةً في الثالثة فأُنشئ لها مشاركان — فهي
//  تُمرَّر بالمرجع بين الدفعات.
// ════════════════════════════════════════════════════════════
class ProcessCandidateImport implements ShouldQueue
{
    use Queueable;

    // دفعةٌ صغيرة عمداً: كل صفٍّ معاملةٌ وتشفير، ودفعةٌ أكبر تُطيل الفجوة بين
    // تحديثين للعدّاد فتبدو الشاشة واقفة وهي تعمل.
    private const CHUNK = 100;

    public int $timeout = 3600;

    public int $tries = 1;   // إعادةُ المحاولة تُعيد إنشاء ما أُنشئ — لا تُعاد

    // ── طابورٌ مستقلّ: `imports` لا `default` ──
    // عشرة آلاف صفٍّ تشغل العامل نحو ربع ساعة. وعاملا `default` مشغولان
    // برسائل التأكيد — وهي قصيرة ومتقطّعة ويجب أن تخرج في حينها. رفعةٌ
    // واحدة على الطابور نفسه تحبس كل رسالة خلفها ربع ساعة، ورفعتان
    // تحبسانها حتى يفرغ الاثنان. فلها طابورها وعاملها.
    public function __construct(public int $batchId)
    {
        $this->onQueue('imports');
    }

    public function handle(CandidateImporter $importer): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch || $batch->status !== 'queued') {
            return;   // حُذفت، أو عولجت مرّة — لا تُعالَج ثانية
        }

        $batch->update(['status' => 'processing', 'started_at' => now()]);

        $rows = $batch->payload ?? [];
        $seen = [];
        $failures = [];
        $created = 0;
        $offset = 0;

        try {
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $result = $importer->import($chunk, $batch->user_id, $offset, $seen);

                $created += count($result['success']);
                $failures = array_merge($failures, $result['failures']);
                $offset += count($chunk);

                $batch->update([
                    'processed_rows' => $offset,
                    'created_count' => $created,
                    'failed_count' => count($failures),
                    'failures' => $failures,
                ]);
            }

            // الحمولة تُفرَّغ عند الانتهاء: صفوفها تحمل هويات وأسماء وسيَراً
            // كاملة، وإبقاؤها بعد أن أدّت غرضها بيانٌ شخصي يُحفظ بلا سبب.
            $batch->update([
                'status' => 'completed',
                'payload' => null,
                'finished_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $batch->user_id,
                'action' => 'IMPORT_CANDIDATES',
                'details' => ['batch' => $batch->id, 'imported' => $created, 'failed' => count($failures)],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // ما أُنشئ قبل الانقطاع يبقى — والعدّاد يقول كم بلغ، فيُستأنف
            // الباقي بملفٍّ مُصحَّح لا بإعادة الملفّ كلّه
            Log::error('candidate import batch failed', ['batch' => $batch->id, 'error' => $e->getMessage()]);
            $batch->update([
                'status' => 'failed',
                'payload' => null,
                'error' => 'تعذّرت معالجة الرفعة — عولج '.$offset.' صفّاً قبل التوقّف',
                'finished_at' => now(),
            ]);
        }
    }
}
