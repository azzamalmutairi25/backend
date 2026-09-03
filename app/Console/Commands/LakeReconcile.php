<?php

namespace App\Console\Commands;

use App\Models\FinalReport;
use App\Services\ReportSnapshotService;
use App\Support\LakeEmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════════════════
//  المطابقة — هل ما في المنصّة موجودٌ في البحيرة؟
//
//  ثلاثة فروقٍ لكلٍّ منها معنى مختلف تماماً:
//    ناقص  — في المنصّة وليس في البحيرة → نقصُ تغذية، يُعاد إرساله
//    مفقود — في البحيرة وليس في المنصّة → حُذف من المصدر. لا حذفَ ناعماً
//            في المنصّة إطلاقاً (لا SoftDeletes ولا deleted_at في أيٍّ من
//            ٤٣ نموذجاً)، وحذفُ مشاركٍ يُسقط تقاريرَه بالتتالي. فهذا
//            متوقَّعٌ لا خطأ، ويُسجَّل شاهداً على أن البحيرة تحفظ ما لم
//            تعد المنصّة تحفظه.
//    محجوب — مُصنَّف، ناقصٌ بالتصميم لا بالخلل.
//
//  المُصنَّف يُستبعَد من تعداد المصدر قبل المقارنة. لولا ذلك لَظهر ناقصاً
//  كلَّ ليلة، ولأُعيد إرسالُه كلَّ ليلة، ولكُتب سطرُ حجبٍ لكلِّ تقريرٍ
//  منه في أكبر جداول المنصّة — إغراقٌ لسجلٍّ لا سياسةَ تقليمٍ له أصلاً.
// ════════════════════════════════════════════════════════════════════════
class LakeReconcile extends Command
{
    protected $signature = 'kafaat:lake:reconcile {--repair : إعادة إرسال الناقص}';

    protected $description = 'مطابقة تقارير المنصّة مع بحيرة التقارير';

    public function handle(ReportSnapshotService $snapshots, LakeEmitter $emitter): int
    {
        if (! config('lake.enabled')) {
            $this->line('البحيرة معطّلة — لا مطابقة.');

            return self::SUCCESS;
        }

        $lake = DB::connection(config('lake.connection'));
        $allowed = (array) config('lake.classifications', ['normal']);

        // تعداد المصدر: المسموح تصنيفُه فقط.
        $sourceIds = FinalReport::whereHas('candidate',
            fn ($q) => $q->whereIn('classification', $allowed))
            ->pluck('assessment_id')->filter()->unique();

        $suppressed = FinalReport::whereHas('candidate',
            fn ($q) => $q->whereNotIn('classification', $allowed))->count();

        // تعدادُ البحيرة عبر العقد لا عبر الإسقاط الداخلي.
        $lakeIds = collect($lake->table('contract_v1.reports')->pluck('assessment_ref'))
            ->filter()->unique();

        $missing = $sourceIds->diff($lakeIds);
        $vanished = $lakeIds->diff($sourceIds);

        $runId = $lake->table('meta.reconciliation_runs')->insertGetId([
            'source_count' => $sourceIds->count(),
            'lake_count' => $lakeIds->count(),
            'missing_count' => $missing->count(),
            'vanished_count' => $vanished->count(),
            'suppressed_count' => $suppressed,
            'divergent_count' => 0,
            'detail' => json_encode([
                'missing' => $missing->take(200)->values(),
                'vanished' => $vanished->take(200)->values(),
            ], JSON_UNESCAPED_UNICODE),
        ], 'run_id');

        $this->table(['المقياس', 'العدد'], [
            ['في المنصّة (غير مُصنَّف)', $sourceIds->count()],
            ['في البحيرة', $lakeIds->count()],
            ['ناقص', $missing->count()],
            ['مفقود من المصدر', $vanished->count()],
            ['محجوب (مُصنَّف)', $suppressed],
        ]);

        $repaired = 0;
        if ($this->option('repair') && $missing->isNotEmpty()) {
            foreach (FinalReport::whereIn('assessment_id', $missing)->with('candidate.sector', 'assessment')->get() as $r) {
                if ($emitter->report($r, 'report.backfilled', $r->status, ['key' => 'reconcile:'.$r->id])) {
                    $repaired++;
                }
            }
            $this->info("أُعيد إرسال: {$repaired}");
        }

        $lake->table('meta.reconciliation_runs')->where('run_id', $runId)
            ->update(['finished_at' => now(), 'repaired_count' => $repaired]);

        return self::SUCCESS;
    }
}
