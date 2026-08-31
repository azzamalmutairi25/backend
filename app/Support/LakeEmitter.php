<?php

namespace App\Support;

use App\Models\FinalReport;
use App\Services\ReportSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ════════════════════════════════════════════════════════════════════════
//  المُصدِّر — نقطة الدخول الوحيدة إلى صندوق الصادر
//
//  واجهةٌ واحدة لا اثنتان: البوّابة (التصنيف) والهبوطُ الآمن (degraded)
//  وصمّامُ الضغط كلُّها في المسار نفسه. لو وُزّعت على واجهتين لَبقيت
//  الضوابطُ في واحدةٍ لا يستدعيها أحد — وهو أسوأ من غيابها، لأنها تبدو
//  موجودة.
//
//  ثلاث قواعد تحكم كلَّ ما هنا:
//    ١) لا يُرمى استثناءٌ إلى الطلب أبداً. اعتمادُ تقريرٍ حكوميّ لا يفشل
//       لأن بحيرةَ تحليلاتٍ تعثّرت. الفشل يُسجَّل ويُبتلع.
//    ٢) لا اتصالَ بالبحيرة من داخل الطلب. يُكتب صفٌّ محلّي فقط.
//    ٣) معطَّلٌ افتراضياً: بلا LAKE_ENABLED لا شيء يحدث ولا كلفةَ تُدفع.
// ════════════════════════════════════════════════════════════════════════

class LakeEmitter
{
    public function __construct(private ReportSnapshotService $snapshots) {}

    public function enabled(): bool
    {
        return (bool) config('lake.enabled');
    }

    /**
     * يُسجّل حدثَ تقرير.
     *
     * يُستدعى بعد نجاح الكتابة المشروطة في المنصّة (لا قبلها): الحدث
     * يصف ما وقع فعلاً، والكتابةُ المشروطة قد تُرجع صفر صفوفٍ فلا يقع شيء.
     *
     * @param  string|null  $toStatus  الحالة بعد الانتقال، صراحةً.
     * @return bool  هل كُتب صفّ.
     */
    public function report(FinalReport $report, string $eventType, ?string $toStatus = null, array $ctx = []): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            $candidate = $report->candidate;

            // مفتاحٌ اشتقاقيّ للحدث: النوع + التقرير + الوجهة + لحظةُ الوقوع.
            // إعادةُ الإرسال بالمفتاح نفسه تُنتج المعرّف نفسه فتُمتصّ في
            // البحيرة، ولا تُنشئ صفّاً ثانياً لحدثٍ واحد.
            $occurredAt = now();
            $key = sprintf('%s:%d:%s:%s', $eventType, $report->id,
                $toStatus ?? $report->status, $ctx['key'] ?? $occurredAt->format('Y-m-d\TH:i:s.u'));

            $degraded = false;
            try {
                $payload = $this->snapshots->freeze($report, $eventType, $toStatus, $ctx);
            } catch (LakeSuppressed $e) {
                // حجبٌ مقصود: لا صفّ، ولا خطأ. يُحصى في المطابقة الليلية
                // مرّةً واحدة لا صفّاً صفّاً — وإلا صار سجلُّ التدقيق
                // يمتلئ كلَّ ليلة بما هو متوقَّع بالتصميم.
                return false;
            } catch (\Throwable $e) {
                // تعذّر البناء: يُحفظ الهيكل ولا يُفقد الحدث. الإسقاط في
                // البحيرة يتخطّى المبتور بدل أن يسقط عليه.
                Log::warning('lake: تعذّر بناء حمولة التقرير', [
                    'report_id' => $report->id, 'event' => $eventType, 'error' => $e->getMessage(),
                ]);
                $payload = $this->snapshots->skeleton($report, $eventType, $toStatus, $e->getMessage());
                $degraded = true;
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

            DB::table('report_lake_outbox')->insertOrIgnore([
                'event_uuid' => LakeRef::eventUuid($key),
                'occurred_at' => $occurredAt,
                'event_type' => $eventType,
                'subject_type' => 'report',
                'source_report_id' => $report->id,
                'source_assessment_id' => $report->assessment_id,
                'person_ref' => $candidate ? LakeRef::person($candidate->id) : null,
                'participant_code' => config('lake.publish.participant_code')
                    ? $candidate?->participant_code : null,
                'sector_id' => $candidate?->sector_id,
                'classification' => $candidate?->classification ?? 'normal',
                'degraded' => $degraded,
                'payload' => $json,
                'payload_sha256' => hash('sha256', $json),
                'payload_bytes' => strlen($json),
                'created_at' => $occurredAt,
            ]);

            return true;
        } catch (\Throwable $e) {
            // القاعدة الأولى: لا يصعد شيءٌ إلى الطلب.
            Log::error('lake: تعذّرت كتابة صندوق الصادر', [
                'report_id' => $report->id, 'event' => $eventType, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** حدثٌ غير مرتبطٍ بتقرير: اللقطة اليومية ولقطة التحليلات. */
    public function snapshot(string $eventType, string $key, array $payload): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            $occurredAt = now();
            $payload['contract_version'] = config('lake.contract_version');
            $payload['event_type'] = $eventType;
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

            DB::table('report_lake_outbox')->insertOrIgnore([
                'event_uuid' => LakeRef::eventUuid($key),
                'occurred_at' => $occurredAt,
                'event_type' => $eventType,
                'subject_type' => str_contains($eventType, 'daily') ? 'day' : 'analytics',
                'degraded' => false,
                'payload' => $json,
                'payload_sha256' => hash('sha256', $json),
                'payload_bytes' => strlen($json),
                'created_at' => $occurredAt,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('lake: تعذّرت كتابة لقطة', ['event' => $eventType, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** تراكمٌ فوق الحدّ يعني أن البحيرة متوقّفة — تنبيهٌ واحد لا تنبيهٌ لكل صفّ. */
    public function backlog(): int
    {
        return (int) DB::table('report_lake_outbox')
            ->whereNull('shipped_at')->whereNull('failed_at')->count();
    }
}
