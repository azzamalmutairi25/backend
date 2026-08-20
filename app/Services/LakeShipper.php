<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ════════════════════════════════════════════════════════════════════════
//  الشاحن — يُفرّغ صندوق الصادر في البحيرة
//
//  يعمل خارج دورة الطلب دائماً (أمر artisan عبر المجدول). لا منفذَ
//  يُفتح، ولا خدمةَ تُنصَّب: intrusion-check.sh يعدّ أيَّ منفذٍ مُستمِعٍ
//  جديد شذوذاً يجب تفسيره، والدفعُ من الداخل يتجنّب ذلك كلَّه.
//
//  المعاملتان لا تُدمجان — لا يمكن ذلك أصلاً بين قاعدتين. الترتيب هو
//  الضمانة:
//    ١) تُدرَج الأحداث وتُسقَط في البحيرة، ثم تُثبَّت.
//    ٢) ثم تُعلَّم مشحونةً في المنصّة.
//  انقطاعٌ بين الاثنتين يُعيد شحنَ ما شُحن — و ON CONFLICT على المعرّف
//  الاشتقاطيّ يمتصّه بلا أثر. الاتجاه المعاكس كان سيفقد أحداثاً.
// ════════════════════════════════════════════════════════════════════════

class LakeShipper
{
    public function conn()
    {
        return DB::connection(config('lake.connection'));
    }

    /**
     * يشحن دفعةً واحدة.
     * @return array{claimed:int,landed:int,projected:int,quarantined:int}
     */
    public function shipOnce(): array
    {
        $size = (int) config('lake.batch_size', 500);
        $out = ['claimed' => 0, 'landed' => 0, 'projected' => 0, 'quarantined' => 0];

        $rows = DB::table('report_lake_outbox')
            ->whereNull('shipped_at')->whereNull('failed_at')
            ->orderBy('id')->limit($size)->get();

        if ($rows->isEmpty()) {
            return $out;
        }
        $out['claimed'] = $rows->count();

        $lake = $this->conn();

        try {
            // الأقسام أوّلاً، ومن أقدم حدثٍ في الدفعة لا من اليوم: التعبئة
            // التاريخية تحمل تواريخ ما قبل النطاق المُهيّأ، وبدون هذا تهبط
            // في قسم DEFAULT ولا تخرج منه أبداً — لأن إنشاء القسم المُغطّي
            // لاحقاً يفشل ما دام فيه صفٌّ يخصّه.
            $oldest = $rows->min('occurred_at');
            $lake->statement('SELECT lake.ensure_partitions_from(?::date, ?)', [
                substr((string) $oldest, 0, 10),
                (int) config('lake.partition_months_ahead', 15),
            ]);

            $batchId = null;
            $landed = 0;

            $lake->transaction(function () use ($lake, $rows, &$batchId, &$landed) {
                $batchId = $lake->table('raw.ingest_batches')->insertGetId([
                    'source_system' => 'kafaat-prod',
                    'source_release' => basename((string) base_path()),
                    'declared_count' => $rows->count(),
                    'first_emitter_seq' => $rows->min('id'),
                    'last_emitter_seq' => $rows->max('id'),
                ], 'batch_id');

                foreach ($rows as $r) {
                    // insertOrIgnore على المفتاح (event_uuid, occurred_at):
                    // إعادةُ شحن ما شُحن لا تُنتج صفّاً ثانياً.
                    $lake->table('raw.report_events')->insertOrIgnore([
                        'event_uuid' => $r->event_uuid,
                        'occurred_at' => $r->occurred_at,
                        'batch_id' => $batchId,
                        'emitter_seq' => $r->id,
                        'contract_version' => config('lake.contract_version'),
                        'event_type' => $r->event_type,
                        'subject_type' => $r->subject_type,
                        'source_report_id' => $r->source_report_id,
                        'source_assessment_id' => $r->source_assessment_id,
                        'person_ref' => $r->person_ref,
                        'participant_code' => $r->participant_code,
                        'sector_id' => $r->sector_id,
                        'classification' => $r->classification,
                        'degraded' => $r->degraded,
                        'payload' => $r->payload,
                        'payload_sha256' => $r->payload_sha256,
                        'payload_bytes' => $r->payload_bytes,
                    ]);
                    $landed++;
                }

                // عددُ ما هبط فعلاً لا عددُ ما نوينا إرساله: الفرق بينهما
                // هو ما يكشف الشحنةَ المبتورة عند المراجعة.
                $actual = $lake->table('raw.report_events')->where('batch_id', $batchId)->count();
                $lake->table('raw.ingest_batches')->where('batch_id', $batchId)
                    ->update(['event_count' => $actual]);

                $lake->statement('SELECT lake.project_batch(?)', [$batchId]);
            });

            $out['landed'] = $landed;
            $out['projected'] = (int) ($lake->table('raw.ingest_batches')
                ->where('batch_id', $batchId)->value('projected_rows') ?? 0);

            // ثبتت البحيرة — الآن تُعلَّم في المنصّة.
            DB::table('report_lake_outbox')->whereIn('id', $rows->pluck('id'))
                ->update(['shipped_at' => now(), 'last_error' => null]);

            return $out;
        } catch (\Throwable $e) {
            $out['quarantined'] = $this->recordFailure($rows, $e);
            Log::error('lake: فشل شحن دفعة', ['error' => $e->getMessage()]);
            return $out;
        }
    }

    /**
     * يُسجّل الفشل ويعزل المُصرّ عليه.
     *
     * بدون الحجر الصحّي يكفي صفٌّ واحدٌ ترفضه القاعدة (تصنيفٌ أفلت،
     * حمولةٌ لا تُسقَط) ليُعيد الشاحنُ الدفعةَ نفسها إلى الأبد فتتوقّف
     * التغذيةُ كلُّها بصمت — وهو النقيض التامّ لما وُضع له شريطُ التعثّر.
     */
    private function recordFailure($rows, \Throwable $e): int
    {
        $msg = substr($e->getMessage(), 0, 1000);
        $max = (int) config('lake.max_attempts', 8);

        DB::table('report_lake_outbox')->whereIn('id', $rows->pluck('id'))
            ->update(['attempts' => DB::raw('attempts + 1'), 'last_error' => $msg]);

        $quarantined = DB::table('report_lake_outbox')
            ->whereIn('id', $rows->pluck('id'))
            ->whereNull('shipped_at')->whereNull('failed_at')
            ->where('attempts', '>=', $max)
            ->update(['failed_at' => now()]);

        if ($quarantined > 0) {
            Log::error('lake: عُزلت صفوفٌ إلى الحجر الصحّي', [
                'count' => $quarantined, 'error' => $msg,
            ]);
        }
        return $quarantined;
    }

    /** يستنزف الصندوق حتى يفرغ أو يبلغ سقفَ الدفعات. */
    public function drain(int $maxBatches = 20): array
    {
        $tot = ['claimed' => 0, 'landed' => 0, 'projected' => 0, 'quarantined' => 0, 'batches' => 0];
        for ($i = 0; $i < $maxBatches; $i++) {
            $r = $this->shipOnce();
            if ($r['claimed'] === 0) break;
            foreach (['claimed', 'landed', 'projected', 'quarantined'] as $k) {
                $tot[$k] += $r[$k];
            }
            $tot['batches']++;
            if ($r['landed'] === 0 && $r['quarantined'] === 0) break;   // لا تقدّم — لا تُعِد بلا نهاية
        }
        return $tot;
    }
}
